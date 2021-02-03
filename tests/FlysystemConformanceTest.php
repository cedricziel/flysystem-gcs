<?php

namespace CedricZiel\FlysystemGcs\Tests;

use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;

class FlysystemConformanceTest extends FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $project = getenv('GOOGLE_CLOUD_PROJECT');
        $bucket = getenv('GOOGLE_CLOUD_BUCKET');

        $adapterConfig = [
            'bucket' => $bucket,
            'projectId' => $project,
        ];

        return new GoogleCloudStorageAdapter(null, $adapterConfig);
    }
}
