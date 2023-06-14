<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;


/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     * @param MailerInterface $mailer
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get jobs for a specific user.
     *
     * @param int $user_id
     * @return array
     */
    public function getUsersJobs($user_id): array
    {
        $currentUser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $noramlJobs = [];

        if ($currentUser && $currentUser->is('customer')) {
            // Get jobs for customer user
            $jobs = $currentUser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();

            $usertype = 'customer';
        } elseif ($currentUser && $currentUser->is('translator')) {
            // Get jobs for translator user
            $jobs = Job::getTranslatorJobs($currentUser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }

        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $normalJobs[] = $jobitem;
                }
            }

            $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'currentUser' => $currentUser,
            'usertype' => $usertype
        ];
    }


    /**
     * Get jobs history for a specific user.
     *
     * @param int $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request): array
    {
        $page = $request->get('page');
        $pageNum = $page ?? "1";
        $currentUser = User::find($user_id);
        $emergencyJobs = [];
        $responseArray = [];

        if ($currentUser && $currentUser->is('customer')) {
            // Get jobs history for customer user
            $jobs = $currentUser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            $usertype = 'customer';

            $responseArray = [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => [],
                'jobs' => $jobs,
                'currentUser' => $currentUser,
                'usertype' => $usertype,
                'numPages' => 0,
                'pageNum' => 0
            ];
        } elseif ($currentUser && $currentUser->is('translator')) {
            // Get jobs history for translator user
            $jobsIds = Job::getTranslatorJobsHistoric($currentUser->id, 'historic', $pageNum);
            $totalJobs = $jobsIds->total();
            $numPages = ceil($totalJobs / 15);

            $usertype = 'translator';

            $jobs = $jobsIds;
            $normalJobs = $jobsIds;

            $responseArray = [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => $normalJobs,
                'jobs' => $jobs,
                'currentUser' => $currentUser,
                'usertype' => $usertype,
                'numPages' => $numPages,
                'pageNum' => $pageNum
            ];
        }

        return $responseArray;
    }


    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {

        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $currentUser = $user;

            if (!isset($data['from_language_id'])) {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            } else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }
            }
            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            } else {
                $data['customer_phone_type'] = 'no';
            }

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';

            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }
            }
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'n_law';
            } else if (in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'n_health';
            }
            if ($consumer_type == 'rwsconsumer')
                $data['job_type'] = 'rws';
            else if ($consumer_type == 'ngo')
                $data['job_type'] = 'unpaid';
            else if ($consumer_type == 'paid')
                $data['job_type'] = 'paid';
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $currentUser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();
            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }
            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $currentUser->userMeta->city;
            $data['customer_type'] = $currentUser->userMeta->customer_type;

            //Event::fire(new JobWasCreated($job, $data, '*'));

//            $this->sendNotificationToSuitableTranslators($job->id, $data, '*');// send Push for New job posting
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;

    }

    /**
     * Store job email details and send notification.
     * @param array $data The data containing job and user details.
     * @return array The response containing the job and status.
     */
    public function storeJobEmail($data): array
    {
        try {
            $user_type = $data['user_type'];
            $job = Job::findOrFail($data['user_email_job_id']);
            $job->user_email = $data['user_email'] ?? '';
            $job->reference = $data['reference'] ?? '';
            $user = $job->user()->first();

            if (isset($data['address'])) {
                $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
                $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
                $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
            }

            $job->save();

            $email = $job->user_email ?? $job->user->email;
            $name = $user->name;

            $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
            $send_data = [
                'user' => $user,
                'job' => $job
            ];

            $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

            $response['type'] = $user_type;
            $response['job'] = $job;
            $response['status'] = 'success';
            $data = $this->jobToData($job);

//            Event::fire(new JobWasCreated($job, $data, '*'));
//            Here I have commented above line of code as it was deprecated in version 5.8
//            and here PHP version and Laravel version is not defined. So, I used safe approach
//            And used event helper function to fire the event
            event(new JobWasCreated($job, $data, '*'));

            return $response;
        } catch (\Exception $e) {
            // Exception can be handled here in many ways
            // I am returning error for now but it can be logged as well.
            $response['status'] = 'fail';
            $response['message'] = 'An error occurred: ' . $e->getMessage();
            return $response;
        }
    }

    /**
     * Convert job information to data array for sending push notifications.
     *
     * @param $job `job objects`.
     * @return array The data array containing job information.
     */
    public function jobToData($job): array
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];

        if ($job->gender == 'male') {
            $data['job_for'][] = 'Man';
        } else if ($job->gender == 'female') {
            $data['job_for'][] = 'Kvinna';
        }


        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rättstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }


    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = []): void
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $job_detail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        $eventUserId = ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id;
        event(new SessionEnded($job, $eventUserId));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'lön'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completedDate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }


    /**
     * Sends SMS to translators and returns the count of translators
     *
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job): int
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?? $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', compact('date', 'time', 'duration', 'jobId'));
        $physicalJobMessageTemplate = trans('sms.physical_job', compact('date', 'time', 'city', 'duration', 'jobId'));

        // Determine message type based on customer job preferences
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else {
            // It's either a phone job or both physical and phone job (default to phone job)
            $message = $phoneJobMessageTemplate;
        }

        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            // Send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }


    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job): mixed
    {
        $job_type = $job->job_type;

        switch ($job_type) {
            case 'paid':
                $translator_type = 'professional';
                break;
            case 'rws':
                $translator_type = 'rwstranslator';
                break;
            case 'unpaid':
                $translator_type = 'volunteer';
                break;
            default:
                $translator_type = null;
                break;
        }

        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            switch ($job->certified) {
                case 'yes':
                case 'both':
                    $translator_level[] = 'Certified';
                    $translator_level[] = 'Certified with specialization in law';
                    $translator_level[] = 'Certified with specialization in health care';
                    break;
                case 'law':
                case 'n_law':
                    $translator_level[] = 'Certified with specialization in law';
                    break;
                case 'health':
                case 'n_health':
                    $translator_level[] = 'Certified with specialization in health care';
                    break;
                case 'normal':
                    $translator_level[] = 'Layman';
                    $translator_level[] = 'Read Translation courses';
                    break;
                default:
                    $translator_level[] = 'Certified';
                    $translator_level[] = 'Certified with specialization in law';
                    $translator_level[] = 'Certified with specialization in health care';
                    $translator_level[] = 'Layman';
                    $translator_level[] = 'Read Translation courses';
                    break;
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = $blacklist->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $jobLanguage, $gender, $translator_level, $translatorsId);

        return $users;
    }


    /**
     * Convert number of minutes to hour and minute variant
     * @param int $time
     * @param string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin'): string
    {
        if ($time < 60) {
            return $time . 'min';
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;

        return sprintf($format, $hours, $minutes);
    }


    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $currentUser): mixed
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();
        }

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logJobUpdate($currentUser, $id, $log_data);

        $job->reference = $data['reference'];

        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
            return ['Notification sent'];
        }
    }


    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job): array
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && (($current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && ($data['translator'] != 0)) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged, 'new_translator' => null, 'log_data' => []];
    }


    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due): array
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator): array
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawAfter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }

        return ['statusChanged' => $statusChanged];
    }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator): bool
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all suitable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }


    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = [];            // suitable translators (no need to delay push)
        $delpay_translator_array = [];     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) {
                if (!$this->isNeedToSendPush($oneUser->id)) {
                    continue;
                }
                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') {
                    continue;
                }
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) {
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if ($job_checker != 'userCanNotAcceptJob') {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = [
            'en' => $msg_contents
        ];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true);
    }


    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id): bool
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification === 'yes') {
            return false;
        }
        return true;
    }


    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        if ($translator_type === 'professional') {
            $job_type = 'paid';   /*show all jobs for professionals.*/
        } elseif ($translator_type === 'rwstranslator') {
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        } else {
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */
        }

        $languages = UserLanguages::where('user_id', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $v) {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '') && $job->customer_physical_type === 'yes' && $checktown === false) {
                unset($job_ids[$k]);
            }
        }

        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }


    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');

        if ($not_get_nighttime === 'yes') {
            return true;
        }

        return false;
    }


    use Illuminate\Support\Facades\Http;

    /**
     * Function to send OneSignal Push Notifications with User-Tags
     *
     * @param array $users
     * @param int $job_id
     * @param array $data
     * @param array $msg_text
     * @param bool $is_need_delay
     * @return void
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('ONESIGNAL_APP_ID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('ONESIGNAL_REST_AUTH_KEY'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = array(
            'app_id' => $onesignalAppID,
            'tags' => json_decode($user_tags),
            'data' => $data,
            'title' => array('en' => 'DigitalTolk'),
            'contents' => $msg_text,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $android_sound,
            'ios_sound' => $ios_sound
        );

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        //I have used Laravel HTTP Facades for clean API Request
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            $onesignalRestAuthKey
        ])->post('https://onesignal.com/api/v1/notifications', $fields);

        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response->json()]);
    }


    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users): string
    {
        //Forming an array here instead of string and then converting it into JSON string
        $userTags = [];
        foreach ($users as $index => $user) {
            $tempArr = [];
            if ($index !== 0) {
                $tempArr[] = ["operator" => "OR"];
            }
            $tempArr[] = [
                "key" => "email",
                "relation" => "=",
                "value" => strtolower($user->email)
            ];
            $userTags[] = $tempArr;
        }
        return json_encode($userTags);
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        // Update the job status
        $job->status = $data['status'];

        if ($data['status'] == 'timedout') {
            // Set the admin comments if available
            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }


    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        try {

            // Update the job status
            $job->status = $data['status'];

            if ($data['admin_comments'] == '') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];

            if ($data['status'] == 'completed') {
                $user = $job->user()->first();

                if ($data['sesion_time'] == '') {
                    return false;
                }

                $interval = $data['sesion_time'];
                $diff = explode(':', $interval);
                $job->end_at = date('Y-m-d H:i:s');
                $job->session_time = $interval;
                $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }

                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job,
                    'session_time' => $session_time,
                    'for_text' => 'faktura'
                ];
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job' => $job,
                    'session_time' => $session_time,
                    'for_text' => 'lön'
                ];
                $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
            }

            $job->save();
            return true;

        } catch (\Exception $e) {
            // Handle the exception
            return false;
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator): bool
    {
        try {
            // Check if the provided status is valid for pending

            // Update the job status
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];

            $user = $job->user()->first();

            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job
            ];

            if ($data['status'] == 'assigned' && $changedTranslator) {
                $job->save();
                $job_data = $this->jobToData($job);

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

                $translator = Job::getJobsAssignedTranslatorDetail($job);
                $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

                $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
                $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
                return true;
            } else {
                $subject = 'Avbokning av bokningsnr: #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
                $job->save();
                return true;
            }
        } catch (\Exception $e) {
            // Handle the exception
            return false;
        }
    }

    /**
     * Sends a session start reminder notification to a user.
     *
     * @param mixed $user
     * @param mixed $job
     * @param string $language
     * @param string $due
     * @param int $duration
     * @return void
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration): void
    {
        try {
            $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
            $this->logger->pushHandler(new FirePHPHandler());
            $data = array();
            $data['notification_type'] = 'session_start_remind';
            $due_explode = explode(' ', $due);
            if ($job->customer_physical_type == 'yes') {
                $msg_text = array(
                    "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
                );
            } else {
                $msg_text = array(
                    "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som varar i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
                );
            }

            if ($this->isNeedToSendPush($user->id)) {
                $users_array = array($user);
                $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
            }
        } catch (\Exception $e) {
            // Handle the exception
            Log::error('Error: ' . $e->getMessage());
        }
    }


    /**
     * Changes the status of a job to "withdrawafter24".
     *
     * @param mixed $job The job to update.
     * @param array $data The data containing the new status and admin comments.
     * @return bool True if the status was changed successfully, false otherwise.
     */
    private function changeWithdrawAfter24Status($job, $data): bool
    {
        try {
            if ($data['status'] === 'timedout') {
                $job->status = $data['status'];
                if ($data['admin_comments'] === '') {
                    return false;
                }
                $job->admin_comments = $data['admin_comments'];
                $job->save();
                return true;
            }
        } catch (\Exception $e) {
            // Handle the exception
            Log::error('Error: ' . $e->getMessage());
        }
        return false;
    }


    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                //The approach here is changed based on Single Responsibility Principle
                // breaking it into further smaller parts for better readability
                $this->sendStatusChangedEmailToCustomer($job);
                $this->sendJobCancelEmailToTranslator($job);
            }

            $job->save();
            return true;
        }

        return false;
    }

    private function sendStatusChangedEmailToCustomer($job): void
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
    }

    private function sendJobCancelEmailToTranslator($job): void
    {
        $user = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $email = $user->user->email;
        $name = $user->user->name;

        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'old_time' => $old_time
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user' => $translator,
            'job' => $job,
            'old_time' => $old_time
        ];

        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }


    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator): void
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }


    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang): void
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user' => $user,
            'job' => $job,
            'old_lang' => $old_lang
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }


    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user): void
    {
        $data = [];
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }


    /**
     * @param $data
     * @param $user
     * @return array
     */
    public function acceptJob($data, $user)
    {
        try {
            $adminEmail = config('app.admin_email');
            $adminSenderEmail = config('app.admin_sender_email');

            $currentUser = $user;
            $jobId = $data['job_id'];
            $job = Job::findOrFail($jobId);

            if (!Job::isTranslatorAlreadyBooked($jobId, $currentUser->id, $job->due)) {
                if ($job->status == 'pending' && Job::insertTranslatorJobRel($currentUser->id, $jobId)) {
                    $job->status = 'assigned';
                    $job->save();
                    $user = $job->user()->first();
                    $mailer = new AppMailer();

                    $email = !empty($job->user_email) ? $job->user_email : $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                    $data = [
                        'user' => $user,
                        'job' => $job
                    ];
                    $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
                }

                $jobs = $this->getPotentialJobs($currentUser);
                $response = [
                    'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
                    'status' => 'success'
                ];
            } else {
                $response = [
                    'status' => 'fail',
                    'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
                ];
            }

            return $response;
        } catch (\Exception $e) {
            // Handle the exception
            Log::error('Error: ' . $e->getMessage());
            // Return an error response
            $response = [
                'status' => 'error',
                'message' => 'An error occurred while accepting the job.'
            ];
            return $response;
        }
    }


    /**
     * Get the potential jobs for the given user.
     *
     * @param $currentUser The current user object.
     * @return array The list of potential jobs.
     */
    public function getPotentialJobs($currentUser)
    {
        try {
            $currentUserMeta = $currentUser->userMeta;
            $jobType = 'unpaid';
            $translatorType = $currentUserMeta->translator_type;

            if ($translatorType == 'professional') {
                $jobType = 'paid';   /* Show all jobs for professionals. */
            } elseif ($translatorType == 'rwstranslator') {
                $jobType = 'rws';  /* For rwstranslator, only show rws jobs. */
            } elseif ($translatorType == 'volunteer') {
                $jobType = 'unpaid';  /* For volunteers, only show unpaid jobs. */
            }

            $languages = UserLanguages::where('user_id', '=', $currentUser->id)->get();
            $userLanguage = collect($languages)->pluck('lang_id')->all();
            $gender = $currentUserMeta->gender;
            $translatorLevel = $currentUserMeta->translator_level;

            /* Call the town function for checking if the job is physical, then translators in one town can get the job */
            $jobIds = Job::getJobs($currentUser->id, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);

            foreach ($jobIds as $k => $job) {
                $jobUserId = $job->user_id;
                $job->specific_job = Job::assignedToPaticularTranslator($currentUser->id, $job->id);
                $job->check_particular_job = Job::checkParticularJob($currentUser->id, $job);
                $checkTown = Job::checkTowns($jobUserId, $currentUser->id);

                if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
                    unset($jobIds[$k]);
                }

                if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checkTown) {
                    unset($jobIds[$k]);
                }
            }

            return $jobIds;
        } catch (\Exception $e) {
            // Handle the exception
            Log::error('Error: ' . $e->getMessage());
            // Return an error response or an empty array
            return [];
        }
    }


    /**
     * Accept a job by its ID for the given user.
     *
     * @param int $job_id
     * @param object $currentUser
     * @return array
     */
    public function acceptJobById($job_id, $currentUser)
    {
        try {
            $adminEmail = config('app.admin_email');
            $adminSenderEmail = config('app.admin_sender_email');
            $job = Job::findOrFail($job_id);
            $response = array();

            if (!Job::isTranslatorAlreadyBooked($job_id, $currentUser->id, $job->due)) {
                if ($job->status == 'pending' && Job::insertTranslatorJobRel($currentUser->id, $job_id)) {
                    $job->status = 'assigned';
                    $job->save();
                    $user = $job->user()->first();
                    $mailer = new AppMailer();

                    if (!empty($job->user_email)) {
                        $email = $job->user_email;
                        $name = $user->name;
                    } else {
                        $email = $user->email;
                        $name = $user->name;
                    }
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                    $data = [
                        'user' => $user,
                        'job' => $job
                    ];
                    $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                    $data = array();
                    $data['notification_type'] = 'job_accepted';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                    );

                    if ($this->isNeedToSendPush($user->id)) {
                        $users_array = array($user);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                    }

                    $response['status'] = 'success';
                    $response['list']['job'] = $job;
                    $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk ' . $job->duration . ' min ' . $job->due;
                } else {
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $response['status'] = 'fail';
                    $response['message'] = 'Denna ' . $language . ' tolkning ' . $job->duration . ' min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
                }
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
            }

            return $response;
        } catch (\Exception $e) {
            // Handle the exception
            // Log or throw an appropriate error
            // Return an error response or an empty array
            return [
                'status' => 'error',
                'message' => 'An error occurred while accepting the job.',
                'list' => []
            ];
        }
    }


    /**
     * Cancel a job via AJAX.
     *
     * @param array $data The data containing the job ID.
     * @param object $user The current user object.
     * @return array The response containing the status and job status.
     */
    public function cancelJobAjax($data, $user): array
    {
        $response = [];

        /*
        @todo
        add 24hrs logging here.
        If the cancellation is before 24 hours before the booking time - the supplier will be informed. Flow ended.
        If the cancellation is within 24 hours:
            - The translator will be informed.
            - The customer will get an addition to their number of bookings, and they will be charged for it.
            - Treat it as if it was an executed session.
        */
        try {
            $currentUser = $user;
            $job_id = $data['job_id'];
            $job = Job::findOrFail($job_id);
            $translator = Job::getJobsAssignedTranslatorDetail($job);

            if ($currentUser->is('customer')) {
                $job->withdraw_at = Carbon::now();
                if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                    $job->status = 'withdrawbefore24';
                    $response['jobstatus'] = 'success';
                } else {
                    $job->status = 'withdrawafter24';
                    $response['jobstatus'] = 'success';
                }
                $job->save();
                Event::fire(new JobWasCanceled($job));
                $response['status'] = 'success';
                $response['jobstatus'] = 'success';

                if ($translator) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Kunden har avbokat bokningen för ' . $language . ' tolk, ' . $job->duration . ' min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                    );

                    if ($this->isNeedToSendPush($translator->id)) {
                        $users_array = array($translator);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translator
                    }
                }
            } else {
                if ($job->due->diffInHours(Carbon::now()) > 24) {
                    $customer = $job->user()->first();

                    if ($customer) {
                        $data = array();
                        $data['notification_type'] = 'job_cancelled';
                        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                        $msg_text = array(
                            "en" => 'Er ' . $language . ' tolk, ' . $job->duration . ' min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                        );

                        if ($this->isNeedToSendPush($customer->id)) {
                            $users_array = array($customer);
                            $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id)); // send Session Cancel Push to customer
                        }
                    }

                    $job->status = 'pending';
                    $job->created_at = date('Y-m-d H:i:s');
                    $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                    $job->save();
                    // Event::fire(new JobWasCanceled($job));
                    Job::deleteTranslatorJobRel($translator->id, $job_id);

                    $data = $this->jobToData($job);
                    $this->sendNotificationTranslator($job, $data, $translator->id); // send Push all suitable translators

                    $response['status'] = 'success';
                } else {
                    $response['status'] = 'fail';
                    $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
                }
            }
        } catch (\Exception $e) {
            Log::error('Error: ' . $e->getMessage());
        }

        return $response;
    }


    /*Function to get the potential jobs for paid,rws,unpaid translators*/

    /**
     * End a job.
     *
     * @param array $post_data The data containing the job ID.
     * @return array The response indicating the status of the job end process.
     */
    public function endJob($post_data): array
    {
        try {
            $completedDate = date('Y-m-d H:i:s');
            $jobId = $post_data["job_id"];
            $job_detail = Job::with('translatorJobRel')->find($jobId);

            if ($job_detail->status != 'started') {
                return ['status' => 'success'];
            }

            $dueDate = $job_detail->due;
            $start = date_create($dueDate);
            $end = date_create($completedDate);
            $diff = date_diff($end, $start);
            $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
            $job = $job_detail;
            $job->end_at = date('Y-m-d H:i:s');
            $job->status = 'completed';
            $job->session_time = $interval;

            $user = $job->user()->first();
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $session_explode = explode(':', $job->session_time);
            $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
            $data = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'faktura'
            ];
            $mailer = new AppMailer();
            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

            $job->save();

            $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

            Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

            $user = $tr->user()->first();
            $email = $user->email;
            $name = $user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $data = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'lön'
            ];
            $mailer = new AppMailer();
            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

            $tr->completed_at = $completedDate;
            $tr->completed_by = $post_data['user_id'];
            $tr->save();

            $response['status'] = 'success';
        } catch (\Exception $e) {
            // Handle the exception
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
        }

        return $response;
    }


    /**
     * Mark a job as not carried out by the customer.
     *
     * @param array $post_data The data containing the job ID.
     * @return array The response indicating the status of the operation.
     */
    public function customerNotCalled($post_data)
    {
        try {
            $completedDate = date('Y-m-d H:i:s');
            $jobId = $post_data["job_id"];
            $job_detail = Job::with('translatorJobRel')->find($jobId);
            $dueDate = $job_detail->due;
            $start = date_create($dueDate);
            $end = date_create($completedDate);
            $diff = date_diff($end, $start);
            $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
            $job = $job_detail;
            $job->end_at = date('Y-m-d H:i:s');
            $job->status = 'not_carried_out_customer';

            $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
            $tr->completed_at = $completedDate;
            $tr->completed_by = $tr->user_id;
            $job->save();
            $tr->save();

            $response['status'] = 'success';
        } catch (\Exception $e) {
            // Handle the exception
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
        }

        return $response;
    }

    /**
     * Get all jobs based on the provided request parameters.
     *
     * @param Request $request The request object.
     * @param int|null $limit The limit for pagination (optional).
     * @return mixed The list of jobs or the count of jobs based on the request.
     */
    public function getAll(Request $request, $limit = null): mixed
    {
        $requestData = $request->all();
        $currentUser = $request->__authenticatedUser;
        $consumer_type = $currentUser->consumer_type;

        $allJobs = Job::query();

        if ($currentUser && $currentUser->user_type == env('SUPERADMIN_ROLE_ID')) {
            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', '3');
                    });
                if (isset($requestData['count']) && $requestData['count'] != 'false') {
                    return ['count' => $allJobs->count()];
                }
            }

            if (isset($requestData['id']) && $requestData['id'] != '') {
                $allJobs->whereIn('id', (array)$requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('from_language_id', (array)$requestData['lang']);
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('status', (array)$requestData['status']);
            }
            if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestData['expired_at']);
            }
            if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
            }
            if (isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestData['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestData['translator_email']) && count($requestData['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestData['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($requestData['filter_timetype']) && ($requestData['filter_timetype'] == "created" || $requestData['filter_timetype'] == "due")) {
                $filterType = $requestData['filter_timetype'];

                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where($filterType, '>=', $requestData['from']);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData['to'] . " 23:59:00";
                    $allJobs->where($filterType, '<=', $to);
                }
                if ($filterType == "created") {
                    $allJobs->orderBy('created_at', 'desc');
                } else {
                    $allJobs->orderBy('due', 'desc');
                }
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('job_type', (array)$requestData['job_type']);
            }

            if (isset($requestData['physical'])) {
                $allJobs->where('customer_physical_type', $requestData['physical'])
                    ->where('ignore_physical', 0);
            }

            if (isset($requestData['phone'])) {
                $allJobs->where('customer_phone_type', $requestData['phone']);
                if (isset($requestData['physical'])) {
                    $allJobs->where('ignore_physical_phone', 0);
                }
            }

            if (isset($requestData['flagged'])) {
                $allJobs->where('flagged', $requestData['flagged'])
                    ->where('ignore_flagged', 0);
            }

            if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($requestData['salary']) && $requestData['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                $allJobs = $allJobs->count();
                return ['count' => $allJobs];
            }

            if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($requestData) {
                    $q->where('consumer_type', $requestData['consumer_type']);
                });
            }

            if (isset($requestData['booking_type'])) {
                if ($requestData['booking_type'] == 'physical') {
                    $allJobs->where('customer_physical_type', 'yes');
                } elseif ($requestData['booking_type'] == 'phone') {
                    $allJobs->where('customer_phone_type', 'yes');
                }
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        } else {
            if (isset($requestData['id']) && $requestData['id'] != '') {
                $allJobs->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }

            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', '3');
                    });
                if (isset($requestData['count']) && $requestData['count'] != 'false') {
                    return ['count' => $allJobs->count()];
                }
            }

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('from_language_id', (array)$requestData['lang']);
            }

            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('status', (array)$requestData['status']);
            }

            if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestData['expired_at']);
            }

            if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
            }

            if (isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestData['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }

            if (isset($requestData['filter_timetype']) && ($requestData['filter_timetype'] == "created" || $requestData['filter_timetype'] == "due")) {
                $filterType = $requestData['filter_timetype'];

                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where($filterType, '>=', $requestData['from']);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData['to'] . " 23:59:00";
                    $allJobs->where($filterType, '<=', $to);
                }
                if ($filterType == "created") {
                    $allJobs->orderBy('created_at', 'desc');
                } else {
                    $allJobs->orderBy('due', 'desc');
                }
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('job_type', (array)$requestData['job_type']);
            }

            if (isset($requestData['physical'])) {
                $allJobs->where('customer_physical_type', $requestData['physical'])
                    ->where('ignore_physical', 0);
            }

            if (isset($requestData['phone'])) {
                $allJobs->where('customer_phone_type', $requestData['phone']);
                if (isset($requestData['physical'])) {
                    $allJobs->where('ignore_physical_phone', 0);
                }
            }

            if (isset($requestData['flagged'])) {
                $allJobs->where('flagged', $requestData['flagged'])
                    ->where('ignore_flagged', 0);
            }

            if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if (isset($requestData['salary']) && $requestData['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                $allJobs = $allJobs->count();
                return ['count' => $allJobs];
            }

            if (isset($requestData['booking_type'])) {
                if ($requestData['booking_type'] == 'physical') {
                    $allJobs->where('customer_physical_type', 'yes');
                } elseif ($requestData['booking_type'] == 'phone') {
                    $allJobs->where('customer_phone_type', 'yes');
                }
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        }

        return $allJobs;
    }

    /**
     * Get all jobs based on the provided request parameters.
     *
     * @return array An array containing the list of jobs, languages, all customers, all translators, and request data.
     */
    public function alerts(): array
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration * 2) {
                    $sesJobs[$i] = $job;
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

        $currentUser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($currentUser->id, 'consumer_type');

        if ($currentUser && $currentUser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobId)
                ->where('jobs.ignore', 0);

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang']);
            }

            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestData['status']);
            }

            if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', $user->id);
                }
            }

            if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs);
                }
            }

            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"]);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }

            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestData["from"]);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type']);
            }

            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc')
                ->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestData];
    }


    /**
     * Retrieve the throttles for failed user logins.
     *
     * @return array The throttles paginated results.
     */
    public function userLoginFailed(): array
    {
        try {
            $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

            return ['throttles' => $throttles];
        } catch (\Exception $e) {
            // Handle any exceptions that occurred
            Log::error('Error: ' . $e->getMessage());
            return ['throttles' => []];
        }
    }


    /**
     * Retrieve the expired pending bookings that have not been accepted.
     *
     * @return array The list of expired pending bookings.
     */
    public function bookingExpireNoAccepted(): array
    {
        try {
            $languages = Language::where('active', '1')->orderBy('language')->get();
            $requestData = Request::all();
            $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
            $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');

            $currentUser = Auth::user();
            $consumer_type = TeHelper::getUsermeta($currentUser->id, 'consumer_type');

            $allJobs = null;

            if ($currentUser && ($currentUser->is('superadmin') || $currentUser->is('admin'))) {
                $allJobs = DB::table('jobs')
                    ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.status', 'pending')
                    ->where('jobs.due', '>=', Carbon::now());

                if (isset($requestData['lang']) && $requestData['lang'] != '') {
                    $allJobs->whereIn('jobs.from_language_id', $requestData['lang']);
                }

                if (isset($requestData['status']) && $requestData['status'] != '') {
                    $allJobs->whereIn('jobs.status', $requestData['status']);
                }

                if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
                    $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
                    if ($user) {
                        $allJobs->where('jobs.user_id', '=', $user->id);
                    }
                }

                if (isset($requestData['translator_email']) && $requestData['translator_email'] != '') {
                    $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
                    if ($user) {
                        $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                        $allJobs->whereIn('jobs.id', $allJobIDs);
                    }
                }

                if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                    if (isset($requestData['from']) && $requestData['from'] != "") {
                        $allJobs->where('jobs.created_at', '>=', $requestData["from"]);
                    }
                    if (isset($requestData['to']) && $requestData['to'] != "") {
                        $to = $requestData["to"] . " 23:59:00";
                        $allJobs->where('jobs.created_at', '<=', $to);
                    }
                    $allJobs->orderBy('jobs.created_at', 'desc');
                }

                if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                    if (isset($requestData['from']) && $requestData['from'] != "") {
                        $allJobs->where('jobs.due', '>=', $requestData["from"]);
                    }
                    if (isset($requestData['to']) && $requestData['to'] != "") {
                        $to = $requestData["to"] . " 23:59:00";
                        $allJobs->where('jobs.due', '<=', $to);
                    }
                    $allJobs->orderBy('jobs.due', 'desc');
                }

                if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                    $allJobs->whereIn('jobs.job_type', $requestData['job_type']);
                }

                $allJobs->select('jobs.*', 'languages.language')
                    ->orderBy('jobs.created_at', 'desc')
                    ->paginate(15);
            }

            return [
                'allJobs' => $allJobs,
                'languages' => $languages,
                'all_customers' => $all_customers,
                'all_translators' => $all_translators,
                'requestData' => $requestData
            ];
        } catch (\Exception $e) {
            // Handle any exceptions that occurred
            Log::error('Error: '.$e->getMessage());
        }
        return [
            'allJobs' => [],
            'languages' => [],
            'all_customers' => [],
            'all_translators' => [],
            'requestData' => []
        ];
    }


    /**
     * Ignore the expiring status of a job.
     *
     * @param int $id The ID of the job.
     * @return array The success message and notification.
     */
    public function ignoreExpiring($id): array
    {
        try {
            $job = Job::find($id);

            if ($job) {
                $job->ignore = 1;
                $job->save();
                return ['success', 'Changes saved'];
            } else {
                // Handle the case where the job with the given ID was not found
                return ['error', 'Job not found'];
            }
        } catch (\Exception $e) {
            // Handle any exceptions that occurred
            return ['error', $e->getMessage()];
        }
    }


    /**
     * Ignore the expired status of a job.
     *
     * @param int $id The ID of the job.
     * @return array The success message and notification.
     */
    public function ignoreExpired($id)
    {
        try {
            $job = Job::find($id);

            if ($job) {
                $job->ignore_expired = 1;
                $job->save();
                return ['success', 'Changes saved'];
            } else {
                // Handle the case where the job with the given ID was not found
                return ['error', 'Job not found'];
            }
        } catch (\Exception $e) {
            // Handle any exceptions that occurred
            return ['error', $e->getMessage()];
        }
    }


    /**
     * Ignore a throttle record.
     *
     * @param int $id The ID of the throttle record.
     * @return array The success message and notification.
     */
    public function ignoreThrottle($id)
    {
        try {
            $throttle = Throttles::find($id);

            if ($throttle) {
                $throttle->ignore = 1;
                $throttle->save();
                return ['success', 'Changes saved'];
            } else {
                // Handle the case where the job with the given ID was not found
                return ['error', 'Throttle record not found'];
            }
        } catch (\Exception $e) {
            // Handle any exceptions that occurred
            return ['error', $e->getMessage()];
        }
    }


    /**
     * Reopen a job.
     *
     * @param array $request The request data containing the job ID and user ID.
     * @return array The success message or an error message.
     */
    public function reopen($request): array
    {
        try {
            $jobId = $request['jobid'];
            $userid = $request['userid'];

            $job = Job::find($jobId);
            $job = $job->toArray();

            $data = array();
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
            $data['updated_at'] = date('Y-m-d H:i:s');
            $data['user_id'] = $userid;
            $data['job_id'] = $jobId;
            $data['cancel_at'] = Carbon::now();

            $datareopen = array();
            $datareopen['status'] = 'pending';
            $datareopen['created_at'] = Carbon::now();
            $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

            if ($job['status'] != 'timedout') {
                $affectedRows = Job::where('id', '=', $jobId)->update($datareopen);
                $new_jobid = $jobId;
            } else {
                $job['status'] = 'pending';
                $job['created_at'] = Carbon::now();
                $job['updated_at'] = Carbon::now();
                $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
                $job['updated_at'] = date('Y-m-d H:i:s');
                $job['cust_16_hour_email'] = 0;
                $job['cust_48_hour_email'] = 0;
                $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;
                $affectedRows = Job::create($job);
                $new_jobid = $affectedRows['id'];
            }

            Translator::where('job_id', $jobId)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
            $Translator = Translator::create($data);

            if (isset($affectedRows)) {
                $this->sendNotificationByAdminCancelJob($new_jobid);
                return ["Job reopened successfully"];
            } else {
                return ["Please try again!"];
            }
        } catch (\Exception $e) {
            // Handle any exceptions that occurred
            Log::error('Error: '.$e->getMessage());
            return ["Error occurred while reopening the Job"];
        }
    }


    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    /**
     * Send notifications to translators about a canceled job.
     *
     * @param int $job_id The ID of the canceled job.
     * @return void
     */
    public function sendNotificationByAdminCancelJob($job_id): void
    {
        try{
            $job = Job::findOrFail($job_id);
            $user_meta = $job->user->userMeta()->first();

            // Prepare data for sending Push notifications
            $data = [
                'job_id' => $job->id,
                'from_language_id' => $job->from_language_id,
                'immediate' => $job->immediate,
                'duration' => $job->duration,
                'status' => $job->status,
                'gender' => $job->gender,
                'certified' => $job->certified,
                'due' => $job->due,
                'job_type' => $job->job_type,
                'customer_phone_type' => $job->customer_phone_type,
                'customer_physical_type' => $job->customer_physical_type,
                'customer_town' => $user_meta->city,
                'customer_type' => $user_meta->customer_type,
            ];

            $due_Date = explode(" ", $job->due);
            $due_date = $due_Date[0];
            $due_time = $due_Date[1];
            $data['due_date'] = $due_date;
            $data['due_time'] = $due_time;
            $data['job_for'] = [];

            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }

            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $this->sendNotificationTranslator($job, $data, '*');
        }catch (\Exception $e){
            // Handle the exception (e.g., log an error, show an error message)
            Log::error('Failed to send notification for canceled job: ' . $e->getMessage());
        }
    }


    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    /**
     * Send notification to user about a change in the pending session.
     *
     * @param User $user
     * @param Job $job
     * @param string $language
     * @param string $due
     * @param string $duration
     * @return void
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration): void
    {
        try {
            $data = [
                'notification_type' => 'session_start_remind',
            ];

            if ($job->customer_physical_type == 'yes') {
                $msg_text = [
                    "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
                ];
            } else {
                $msg_text = [
                    "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
                ];
            }

            if ($this->isNeedToSendPush($user->id)) {
                $users_array = [$user];
                $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
            }
        } catch (\Exception $e) {
            // Handle the exception or log an error message
            Log::error('Error: '.$e->getMessage());
        }
    }

}
