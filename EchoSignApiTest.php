<?php

namespace Adobe\EchoSign\GoogleBundle\Tests\Api;

use Adobe\EchoSign\GoogleBundle\Api\EchoSignApi;
use Adobe\EchoSign\GoogleBundle\Model\EchoSignRegistrationForm;

class EchoSignApiTest extends \PHPUnit_Framework_TestCase
{
    public function testRegisterUser()
    {
        $API = new EchoSignApi();
        $user = new EchoSignRegistrationForm();

        $user
            ->setEmail("echosign@go2.pl")
            ->setPassword("johndoe")
            ->setFirstName("John")
            ->setLastName("Doe");

        // Cannot test successful, so let's test almost successful
        $this->assertFalse($API->registerUser($user));
        $this->assertEquals("EXISTING_USER", $API->getLastStatus());
    }

    public function testIssueAccessToken()
    {
        $API = new EchoSignApi();
        $echosignUser = new EchoSignRegistrationForm();

        // Successful authentication
        $token = $API->issueAccessToken('echosign@go2.pl', 'johndoe');
        $this->assertRegExp("/^[a-zA-Z0-9_\-]{32,}/", $token);

        // Unsuccessful authentication
        $echosignUser
            ->setPassword("wrong_password");
        $token = $API->issueAccessToken($echosignUser, 'echosign@go2.pl', 'wrong');
        $this->assertNull($token);
    }
}