<?php

namespace DTApi\Http\Controllers;

use App\Http\Controllers\Controller;
use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use Illuminate\Routing\ResponseFactory;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected BookingRepository $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $userType = $request->__authenticatedUser->{'user_type'};
            $isAdmin = $userType === env('ADMIN_ROLE_ID');
            $isSuperAdmin = $userType === env('SUPERADMIN_ROLE_ID');

            if ($user_id = $request->get('user_id')) {
                $response = $this->repository->getUsersJobs($user_id);
            } elseif ($isAdmin || $isSuperAdmin) {
                $response = $this->repository->getAll($request);
            } else {
                $response = [];
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred.'], 500);
        }
    }


    /**
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            $job = $this->repository->with('translatorJobRel.user')->find($id);

            if (!$job) {
                throw new \Exception('Job not found');
            }

            return response()->json($job);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $user = $request->{'__authenticatedUser'};

            $response = $this->repository->store($user, $data);

            return response()->json($response, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error storing data'], 500);
        }
    }


    /**
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function update($id, Request $request) : JsonResponse
    {
        try {
            $data = $request->except('_token', 'submit');
            $user = $request->{'__authenticatedUser'};

            $response = $this->repository->updateJob($id, $data, $user);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error updating job'], 500);
        }
    }


    /**
     * Send immediate job email.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function immediateJobEmail(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $response = $this->repository->storeJobEmail($data);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error sending job email'], 500);
        }
    }


    /**
     * Get job history.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getHistory(Request $request): JsonResponse
    {
        if ($user_id = $request->input('user_id')) {
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response()->json($response);
        }

        return response()->json([], 200);
    }

    /**
     * Accept a job.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function acceptJob(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $user = $request->{'__authenticatedUser'};

            $response = $this->repository->acceptJob($data, $user);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error accepting job'], 500);
        }
    }


    /**
     * Accept a job with ID.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function acceptJobById(Request $request): JsonResponse
    {
        try {
            $job_id = $request->input('job_id');
            $user = $request->{'__authenticatedUser'};

            $response = $this->repository->acceptJobById($job_id, $user);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error accepting job'], 500);
        }
    }


    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        try {
            $data = $request->all();
            $user = $request->{'__authenticatedUser'};

            $response = $this->repository->cancelJobAjax($data, $user);

            return response()->json($response);
        }catch (\Exception $e){
            return response()->json(['error' => 'Error canceling the job'], 500);
        }
    }

    /**
     * End a job.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function endJob(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $response = $this->repository->endJob($data);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error ending job'], 500);
        }
    }

    /**
     * Mark customer as not called.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function customerNotCalled(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $response = $this->repository->customerNotCalled($data);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error marking customer as not called'], 500);
        }
    }

    /**
     * Get potential jobs.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPotentialJobs(Request $request): JsonResponse
    {
        try {
            $user = $request->{'__authenticatedUser'};

            $response = $this->repository->getPotentialJobs($user);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error retrieving potential jobs'], 500);
        }
    }

    /**
     * Update distance feed and job details.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function distanceFeed(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $distance = $data['distance'] ?? '';
            $time = $data['time'] ?? '';
            $jobid = $data['jobid'] ?? '';
            $session = $data['session_time'] ?? '';
            $flagged = isset($data['flagged']) && $data['flagged'] == 'true';
            $manually_handled = isset($data['manually_handled']) && $data['manually_handled'] == 'true';
            $by_admin = isset($data['by_admin']) && $data['by_admin'] == 'true';
            $admincomment = $data['admincomment'] ?? '';

            if ($time || $distance) {
                Distance::where('job_id', '=', $jobid)->update([
                    'distance' => $distance,
                    'time' => $time
                ]);
            }

            if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
                Job::where('id', '=', $jobid)->update([
                    'admin_comments' => $admincomment,
                    'flagged' => $flagged,
                    'session_time' => $session,
                    'manually_handled' => $manually_handled,
                    'by_admin' => $by_admin
                ]);
            }

            return response()->json('Record updated!');
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error updating record'], 500);
        }
    }


    /**
     * Reopen a job.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reopen(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $response = $this->repository->reopen($data);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error reopening job'], 500);
        }
    }


    /**
     * Resend notifications for a job.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendNotifications(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $job = $this->repository->find($data['jobid']);
            $job_data = $this->repository->jobToData($job);
            $this->repository->sendNotificationTranslator($job, $job_data, '*');

            return response()->json(['success' => 'Push sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error resending notifications'], 500);
        }
    }



    /**
     * Resends SMS notifications to the translator for a job.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resendSMSNotifications(Request $request):JsonResponse
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response()->json(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error resending sms notifications'], 500);
        }
    }


}
