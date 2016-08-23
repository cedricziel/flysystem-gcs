<?php

namespace CedricZiel\FlysystemGcs\Tests;

use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;

class GoogleCloudStorageAdapterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var string
     */
    protected $project;

    /**
     * @var string
     */
    protected $bucket;

    public function setUp()
    {
        $this->project = getenv('GCLOUD_PROJECT');
        $this->bucket = getenv('GCLOUD_BUCKET');
    }

    /**
     * @test
     */
    public function testAnAdapterCanBeCreatedWithMinimalConfiguration()
    {
        $minimalConfig = [
            'bucket'    => 'test',
            'projectId' => 'test',
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);
    }

    public function testCanListContentsOfADirectory()
    {
        $minimalConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $contents = $adapter->listContents();

        $testContents = $adapter->listContents('/test/');
    }

    /**
     * @test
     */
    public function testFilesCanBeUploaded()
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_content/{$testId}_public_test.jpg";

        $minimalConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $config = new Config([]);
        $adapter->write(
            $destinationPath,
            file_get_contents(__DIR__.'/../res/Auswahl_126.png'),
            $config
        );

        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PUBLIC);

        $testId = uniqid('', true);
        $destinationPath = "/test_content/{$testId}_private_test.jpg";

        $config = new Config([]);
        $adapter->write(
            $destinationPath,
            file_get_contents(__DIR__.'/../res/Auswahl_126.png'),
            $config
        );

        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PUBLIC);
        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PRIVATE);
    }

    /**
     * @test
     */
    public function canCreateDirectories()
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_content{$testId}";

        $minimalConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $config = new Config([]);
        $this->assertNotFalse($adapter->createDir($destinationPath, $config));
        $this->assertTrue($adapter->deleteDir($destinationPath));
    }
}
