<?php

namespace Tests\Unit;

use DTApi\Models\User;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use App\Repository\UserRepository;

class UserRepositoryTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testConstructor()
    {
        // Create a mock instance of the User model
        $userModelMock = $this->createMock(User::class);

        // Creating an instance of the UserRepository with the mock User model
        $userRepository = new UserRepository($userModelMock);

        // Asserting to check if UserRepository was created successfully
        $this->assertInstanceOf(UserRepository::class, $userRepository);

    }

    /**
     * @throws Exception
     */
    public function testCreateOrUpdate()
    {
        // Create a mock instance of the User model
        $userModelMock = $this->createMock(User::class);

        // Creating an instance of the UserRepository with the mock User model
        $userRepository = new UserRepository($userModelMock);

        // Prepare the test data
        $id = 1;
        $request = [
            'role' => 'admin',
            'name' => 'John Doe',
            'company_id' => 1,
            'department_id' => 2,
            'email' => 'test.example@digitaltolk.com',
            'dob_or_orgid' => '52',
            'phone' => '+4512345678',
            'mobile' => '+46-71-334-8475',
        ];

        // Calling createOrUpdate method
        $result = $userRepository->createOrUpdate($id, $request);

        // Assert that the result is not false
        $this->assertNotFalse($result);
    }

    /**
     * @throws Exception
     */
    public function testEnable()
    {
        // Create a mock instance of the User model
        $userModelMock = $this->createMock(User::class);

        // Set up expectations for the mock model's findOrFail method
        $userModelMock->expects($this->once())
            ->method('findOrFail')
            ->willReturnSelf();

        // Set up expectations for the mock model's save method
        $userModelMock->expects($this->once())
            ->method('save');

        // Creating an instance of the UserRepository with the mock User model
        $userRepository = new UserRepository($userModelMock);

        // Call the enable method
        $userRepository->enable(1);
    }

    /**
     * @throws Exception
     */
    public function testDisable()
    {
        // Create a mock instance of the User model
        $userModelMock = $this->createMock(User::class);

        // Set up expectations for the mock model's findOrFail method
        $userModelMock->expects($this->once())
            ->method('findOrFail')
            ->willReturnSelf();

        // Set up expectations for the mock model's save method
        $userModelMock->expects($this->once())
            ->method('save');

        // Creating an instance of the UserRepository with the mock User model
        $userRepository = new UserRepository($userModelMock);

        // Call the disable method
        $userRepository->disable(1);
    }

    /**
     * @throws Exception
     */
    public function testGetTranslators()
    {
        // Create a mock instance of the User model
        $userModelMock = $this->createMock(User::class);

        // Set up expectations for the mock model's where and get methods
        $userModelMock->expects($this->once())
            ->method('where')
            ->with('user_type', 2)
            ->willReturnSelf();

        $userCollectionMock = $this->createMock(Collection::class);
        $userModelMock->expects($this->once())
            ->method('get')
            ->willReturn($userCollectionMock);

        // Create an instance of the UserRepository with the mock model
        $userRepository = new UserRepository($userModelMock);

        // Call the getTranslators method
        $result = $userRepository->getTranslators();

        // Assert that the result is the expected collection
        $this->assertInstanceOf(Collection::class, $result);
    }
}
