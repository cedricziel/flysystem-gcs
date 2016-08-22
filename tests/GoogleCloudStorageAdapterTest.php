<?php

namespace CedricZiel\FlysystemGcs\Tests;

use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;

class GoogleCloudStorageAdapterTest extends \PHPUnit_Framework_TestCase
{
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
}
