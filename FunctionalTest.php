<?php

namespace Adobe\EchoSign\GoogleBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FunctionalTest extends WebTestCase
{
    CONST USERNAME = 'korvinio@gmail.com';
    CONST GOOGLE_PASSWORD = 'dima!23pas';
    CONST ECHOSIGN_PASSWORD = 'qweewq';
    CONST TEST_FILE_NAME = 'test.txt';
    CONST HOST = 'http://dev.google.thinksmart.com';
    CONST FILE_ID = '0BzoNn297GG1IVWItb0tZTHVmZXM';
    CONST USER_ID = '112752094609379131825';

    /**
     * @var \Behat\Mink\Session
     */
    private $session;
    /**
     * @var Router
     */
    private $router;
    private $documentName;

    public function setUp()
    {
        static::$kernel = static::createKernel();
        static::$kernel->boot();
        $this->router = static::$kernel->getContainer()->get('router');
    }

    public function testRegistrationForm()
    {
        $client = static::createClient();

        $registrationUrl = self::HOST.$this->router->generate('echosign_registration');
        $crawler = $client->request("GET", $registrationUrl);

        $this->assertGreaterThan(
            0,
            $crawler->filter('html:contains("Create Your Free Adobe EchoSign Account")')->count()
        );

        $form = $crawler->selectButton("Create")->form();
        $form["registration[firstName]"] = "wrong";
        $form["registration[lastName]"] = "wrong";
        $form["registration[email]"] = "wrong@wrong.wrong";
        $form["registration[password]"] = "wrong";
        $form["registration[passwordConfirm]"] = "wrong";
        $crawler = $client->submit($form);

        $this->assertGreaterThan(
            0,
            $crawler->filter('html:contains("A user with the specified email already exists")')->count()
        );
    }

    public function testSendToSign()
    {
        // Init browser
        $driver = new \Behat\Mink\Driver\Selenium2Driver(
            'firefox', 'base_url'
        );
        $this->session = new \Behat\Mink\Session($driver);
        $this->session->start();

        // Google Authentication
        $this->session->visit('https://accounts.google.com/ServiceLogin?service=wise&passive=1209600&continue=https%3A%2F%2Fdrive.google.com%2F&followup=https%3A%2F%2Fdrive.google.com%2F&ltmpl=drive');
        $page = $this->session->getPage();
        $page->find('css', '#gaia_loginform > #Email')->setValue(self::USERNAME);
        $page->find('css', '#gaia_loginform > #Passwd')->setValue(self::GOOGLE_PASSWORD);
        $page->find('css', '#gaia_loginform #signIn')->press();
        $this->session->wait(4000, 'onload');
        $this->assertEquals(
            'https://drive.google.com/?authuser=0#my-drive',
            $this->session->getCurrentUrl()
        );

        // Run application
        $this->session->visit('https://drive.google.com/?authuser=0');
        $page = $this->session->getPage();
        $items = $page->findAll('css', '.doclist-table > .doclist-tbody > .doclist-tr .doclist-content-wrapper .doclist-name');
        foreach ($items as $item) {
            $titel[] =  $item->getAttribute('title');
            if (self::TEST_FILE_NAME == $item->getAttribute('title')) {
                $item->rightClick();
                break;
            }
        }
        $items = $page->findAll('css', '.goog-menuitem-caption');
        foreach ($items as $item) {
            if ($item->getText() == 'Open with') {
                $item->click();
            }
        }
        $items = $page->findAll('css', '.goog-menuitem-caption');
        foreach ($items as $item) {
            if (method_exists($item, 'getText') && $item->getText() == 'eSign with Adobe EchoSign (dev)') {
                $item->click();
            }
        }

        // Login to app
        $this->session->wait(10000, 'onload');
        $this->session->switchToWindow('EchoSign Google Drive application');
        $loginUrl = self::HOST.$this->router->generate('echosign_login');
        $this->session->visit($loginUrl);
        if ($this->session->getCurrentUrl() == $loginUrl) {
            //Test existing login form
            $this->session->wait(2000, 'onload');
            $title = $this->session->getPage()->find('css', '.strong')->getText();
            $this->assertEquals(
                "Sign In To Your Account",
                $title
            );

            $page->find('css', '#authentication_email')->setValue(self::USERNAME);
            $page->find('css', '#authentication_password')->setValue(self::ECHOSIGN_PASSWORD);
            $page->find('css', 'input[type=submit]')->press();
        }

        //Test send sign document
        $this->documentName = "Test Sign Document ".date('Y-m-d h i');
        $page->find('css', '#recipientInput')->setValue('korvinio@tut.by');
        $page->find('css', '#sign_name')->setValue($this->documentName);
        $page->find('css', '#sign_message')->setValue('Test message');
        $page->find('css', 'input[type=submit]')->press();

        $this->session->wait(8000, 'onload');
        $this->session->switchToIFrame('sign');
        $this->session->wait(15000);
        $page = $this->session->getPage();
        $page->find('css', '#submit')->press();

        $this->session->wait(4000, 'onload');
        $page = $this->session->getPage();
        $title = $page->find('css', '#post-send-text h1')->getText();
        $this->assertRegExp('/successfully\ssent/', $title);
        $this->session->wait(10000);

//        $ = static::$kernel->getContainer()->get('router');

        $this->session->stop();
    }

    public function testCreateFolder()
    {
        $client = static::createClient();
    }
}
