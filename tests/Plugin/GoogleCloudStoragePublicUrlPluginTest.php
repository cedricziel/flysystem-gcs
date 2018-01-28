<?php

namespace CedricZiel\FlysystemGcs\Tests\Plugin;

use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use CedricZiel\FlysystemGcs\Plugin\GoogleCloudStoragePublicUrlPlugin;
use PHPUnit\Framework\TestCase;

class GoogleCloudStoragePublicUrlPluginTest extends TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testThePluginNeedsBucketOrUrl()
    {
        $plugin = new GoogleCloudStoragePublicUrlPlugin();
    }

    /**
     * @dataProvider urlPrefixDataProvider
     *
     * @param string $urlPrefix
     * @param string $objectPath
     * @param string $expectedUrl
     */
    public function testPluginCanUseAUrlPrefix($urlPrefix, $objectPath, $expectedUrl)
    {
        $plugin = new GoogleCloudStoragePublicUrlPlugin(['url' => $urlPrefix]);

        $this->assertEquals($expectedUrl, $plugin->handle($objectPath));
    }

    public function urlPrefixDataProvider()
    {
        return [
            ['foo://bar', 'bar/baz.txt', 'foo://bar/bar/baz.txt'],
            ['foo://bar', '/bar/baz.txt', 'foo://bar/bar/baz.txt'],
            ['foo://bar/', '/bar/baz.txt', 'foo://bar/bar/baz.txt'],
            ['foo://bar/baz/', '/bar/baz.txt', 'foo://bar/baz/bar/baz.txt'],
        ];
    }

    /**
     * @dataProvider bucketPrefixDataProvider
     *
     * @param string $bucketName
     * @param string $objectPath
     * @param string $expectedUrl
     */
    public function testCanUseBucketOption($bucketName, $objectPath, $expectedUrl)
    {
        $plugin = new GoogleCloudStoragePublicUrlPlugin(['bucket' => $bucketName]);

        $this->assertEquals($expectedUrl, $plugin->handle($objectPath));
    }

    public function bucketPrefixDataProvider()
    {
        return [
            ['my-bucket', 'bar/baz.txt', GoogleCloudStorageAdapter::GCS_BASE_URL.'/my-bucket/bar/baz.txt'],
            ['my-bucket', '/bar/baz.txt', GoogleCloudStorageAdapter::GCS_BASE_URL.'/my-bucket/bar/baz.txt'],
            ['my-bucket', '/bar/baz.txt', GoogleCloudStorageAdapter::GCS_BASE_URL.'/my-bucket/bar/baz.txt'],
            ['my-bucket/prefix/in/bucket', '/bar/baz.txt', GoogleCloudStorageAdapter::GCS_BASE_URL.'/my-bucket/prefix/in/bucket/bar/baz.txt'],
            ['my-bucket/prefix/in/bucket/', '/bar/baz.txt', GoogleCloudStorageAdapter::GCS_BASE_URL.'/my-bucket/prefix/in/bucket/bar/baz.txt'],
        ];
    }
}
