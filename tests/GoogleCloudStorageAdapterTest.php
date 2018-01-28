<?php

namespace CedricZiel\FlysystemGcs\Tests;

use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use CedricZiel\FlysystemGcs\Plugin\GoogleCloudStoragePublicUrlPlugin;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;

class GoogleCloudStorageAdapterTest extends TestCase
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

    public function testAnAdapterCanBeCreatedWithMinimalConfiguration()
    {
        $minimalConfig = [
            'bucket'    => 'test',
            'projectId' => 'test',
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $this->assertInstanceOf(GoogleCloudStorageAdapter::class, $adapter);
    }

    public function testCanListContentsOfADirectory()
    {
        $testDirectory = '/'.uniqid('test_', true).'/';
        $minimalConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $config = new Config([]);
        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $contents = $adapter->listContents('/');

        $this->assertNotFalse($adapter->createDir($testDirectory, $config));
        $this->assertTrue($adapter->has($testDirectory));

        $testContents = $adapter->listContents($testDirectory);

        $this->assertTrue($adapter->deleteDir($testDirectory));
        $this->assertFalse($adapter->has($testDirectory));
        $this->assertFalse($adapter->has(ltrim($testDirectory, '/')));
        $this->assertFalse($adapter->has(rtrim(ltrim($testDirectory, '/'), '/')));
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

        $this->assertTrue($adapter->has($destinationPath));

        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PUBLIC);

        // check visibility
        $this->assertTrue($adapter->delete($destinationPath));
        $this->assertFalse($adapter->has($destinationPath));

        $testId = uniqid('', true);
        $destinationPath = "/test_content/{$testId}_private_test.jpg";

        $config = new Config([]);
        $adapter->write(
            $destinationPath,
            file_get_contents(__DIR__.'/../res/Auswahl_126.png'),
            $config
        );

        $this->assertTrue($adapter->has($destinationPath));

        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PUBLIC);
        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PRIVATE);

        $this->assertTrue($adapter->delete($destinationPath));
        $this->assertFalse($adapter->has($destinationPath));
    }

    /**
     * @test
     */
    public function canCreateDirectories()
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_content-canCreateDirectories-{$testId}";

        $minimalConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $config = new Config([]);
        $this->assertNotFalse($adapter->createDir($destinationPath, $config));
        $this->assertTrue($adapter->deleteDir($destinationPath));
    }

    /**
     * @test
     */
    public function testAFileCanBeRead()
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_testAFileCanBeRead-{$testId}_text.txt";
        $content = 'testAFileCanBeRead';

        $minimalConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $config = new Config([]);
        $adapter->write($destinationPath, $content, $config);

        $this->assertTrue($adapter->has($destinationPath), 'Once writte, the adapter can see the object in GCS');
        $this->assertEquals(
            $content,
            $adapter->read($destinationPath)['contents'],
            'The content of the GCS object matches exactly the input'
        );
        $this->assertEquals(
            'file',
            $adapter->getMetadata($destinationPath)['type'],
            'The object type is interpreted correctly'
        );
        $this->assertEquals(
            'text/plain',
            $adapter->getMimetype($destinationPath)['mimetype'],
            'The mime type is available'
        );
        $this->assertEquals(
            strlen($content),
            $adapter->getSize($destinationPath)['size'],
            'The size from the metadata matches the input'
        );
        $this->assertInternalType(
            'int',
            $adapter->getTimestamp($destinationPath)['timestamp'],
            'The object has a timestamp'
        );
        $this->assertGreaterThan(
            0,
            $adapter->getTimestamp($destinationPath)['timestamp'],
            'The timestamp from GCS is added to the metadata correctly'
        );
        $this->assertTrue($adapter->delete($destinationPath));
        $this->assertFalse($adapter->has($destinationPath));
    }

    /**
     * @test
     */
    public function testDeletingNonExistentObjectsWillNotFail()
    {
        $minimalConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $this->assertTrue($adapter->delete('no_file_in_storage.txt'));
    }

    /**
     * @test
     */
    public function testPrefixesCanBeUsed()
    {
        $testId = uniqid();
        $testPrefix = "my/prefix/testPrefixesCanBeUsed-{$testId}/";

        $simpleConfig = new Config([]);
        $prefixedAdapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
            'prefix'    => $testPrefix,
        ];

        $prefixedAdapter = new GoogleCloudStorageAdapter(null, $prefixedAdapterConfig);

        $unprefixedAdapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $unprefixedAdapter = new GoogleCloudStorageAdapter(null, $unprefixedAdapterConfig);

        $path = 'test.txt';
        $contents = 'This is just a simple melody....';
        $prefixedAdapter->write($path, $contents, $simpleConfig);
        $this->assertTrue($prefixedAdapter->has($path));
        $this->assertEquals($contents, $prefixedAdapter->read($path)['contents']);

        $this->assertTrue($prefixedAdapter->has($path));
        $this->assertTrue($unprefixedAdapter->has($testPrefix.$path));

        $this->assertTrue($prefixedAdapter->delete($path));
    }

    /**
     * @test
     */
    public function testCanBeWrappedWithAFilesystem()
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_content-testCanBeWrappedWithAFilesystem-{$testId}/test.txt";

        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);
        $contents = 'This is just a simple melody....';

        $fs->write($destinationPath, $contents);

        $data = $fs->read($destinationPath);

        $this->assertEquals($contents, $data);
        $this->assertTrue($fs->has($destinationPath));

        $this->assertTrue($fs->delete($destinationPath), 'Files can be removed without errors');
        $this->assertFalse($fs->has($destinationPath), 'They are gone after the previous operation');
    }

    /**
     * @test
     */
    public function testVisibilityCanBeSetOnWrite()
    {
        $testId = uniqid('', true);
        $destinationPathPrivate = "/test_content-testVisibilityCanBeSetOnWrite-{$testId}/test-private.txt";
        $destinationPathPublic = "/test_content-testVisibilityCanBeSetOnWrite-{$testId}/test-public.txt";

        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);
        $contents = 'This is just a simple melody....';

        // Test pre-setting private visibility
        $fs->write(
            $destinationPathPrivate,
            $contents,
            [
                'visibility' => AdapterInterface::VISIBILITY_PRIVATE,
            ]
        );

        $data = $fs->read($destinationPathPrivate);

        $this->assertEquals($contents, $data);
        $this->assertTrue($fs->has($destinationPathPrivate));
        $this->assertEquals(AdapterInterface::VISIBILITY_PRIVATE, $fs->getVisibility($destinationPathPrivate));

        // Test pre-setting public visibility
        $fs->write(
            $destinationPathPublic,
            $contents,
            [
                'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
            ]
        );

        $data = $fs->read($destinationPathPublic);

        $this->assertEquals($contents, $data);
        $this->assertTrue($fs->has($destinationPathPublic));
        $this->assertEquals(AdapterInterface::VISIBILITY_PUBLIC, $fs->getVisibility($destinationPathPublic));

        $this->assertTrue($fs->delete($destinationPathPrivate));
        $this->assertTrue($fs->delete($destinationPathPublic));
    }

    /**
     * @test
     */
    public function testCanUpdateAFile()
    {
        $testId = uniqid('', true);
        $destination = "/test_content-testCanUpdateAFile-{$testId}/test.txt";
        $initialContent = 'testCanUpdateAFile';
        $updatedContent = 'testCanUpdateAFile-update';

        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);

        $fs->put($destination, $initialContent);
        $this->assertTrue($fs->has($destination));
        $this->assertTrue($fs->update($destination, $updatedContent));
        $this->assertEquals($updatedContent, $fs->read($destination));
        $this->assertTrue($fs->delete($destination));
    }

    public function testCanCopyObject()
    {
        $testId = uniqid('', true);
        $destination = "/test_content-testCanCopyObject-{$testId}/test.txt";
        $copyDestination = "/test_content-testCanCopyObject-{$testId}/test-copy.txt";
        $initialContent = 'testCanCopyObject';

        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);

        $fs->put($destination, $initialContent);
        $this->assertEquals($initialContent, $fs->read($destination));
        $this->assertFalse($fs->has($copyDestination));
        $this->assertTrue($fs->copy($destination, $copyDestination));

        $this->assertTrue($fs->delete($destination));
        $this->assertTrue($fs->delete($copyDestination));
    }

    public function testCanRenameObject()
    {
        $testId = uniqid('', true);
        $originalDestination = "/test_content-testCanRenameObject-{$testId}/test.txt";
        $renameDestination = "/test_content-testCanRenameObject-{$testId}/test-rename.txt";
        $initialContent = 'testCanRenameObject';

        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);

        $fs->put($originalDestination, $initialContent);
        $this->assertEquals($initialContent, $fs->read($originalDestination));
        $this->assertFalse($fs->has($renameDestination));
        $this->assertTrue($fs->rename($originalDestination, $renameDestination));
        $this->assertFalse($fs->has($originalDestination));
        $this->assertTrue($fs->has($renameDestination));

        $this->assertTrue($fs->delete($renameDestination));
    }

    public function testObjectsCanBeHandledThroughStreams()
    {
        $testId = uniqid('', true);
        $originalDestination = "/test_content-testObjectsCanBeHandledThroughStreams-{$testId}/test.txt";
        $initialContent = 'testObjectsCanBeHandledThroughStreams';
        $updatedContent = 'testObjectsCanBeHandledThroughStreamsUpdated';

        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $fs = new Filesystem($adapter);

        // put through stream
        $contentStream = fopen('data://text/plain;base64,' . base64_encode($initialContent),'r');
        $fs->putStream($originalDestination, $contentStream);

        // read through stream
        $this->assertEquals($initialContent, stream_get_contents($fs->readStream($originalDestination)));

        // update through stream
        $updateContentStream = fopen('data://text/plain;base64,' . base64_encode($updatedContent),'r');
        $this->assertTrue($fs->updateStream($originalDestination, $updateContentStream));

        // read updated content through stream
        $this->assertEquals($updatedContent, stream_get_contents($fs->readStream($originalDestination)));

        $this->assertTrue($fs->delete($originalDestination));
        $this->assertFalse($fs->has($originalDestination));

        $contentStream = fopen('data://text/plain;base64,' . base64_encode($initialContent),'r');
        $this->assertTrue($fs->writeStream($originalDestination, $contentStream));
        $this->assertEquals($initialContent, stream_get_contents($fs->readStream($originalDestination)));
        $this->assertTrue($fs->delete($originalDestination));
        $this->assertFalse($fs->has($originalDestination));
    }

    /**
     * @dataProvider urlPrefixDataProvider
     *
     * @param string $urlPrefix
     * @param string $objectPath
     * @param string $expectedUrl
     */
    public function testObjectsPublicUrlsCanUseCustomUrls($urlPrefix, $objectPath, $expectedUrl)
    {
        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $fs = new Filesystem($adapter);
        $fs->addPlugin(new GoogleCloudStoragePublicUrlPlugin(['url' => $urlPrefix]));

        $this->assertEquals($expectedUrl, $fs->getUrl($objectPath));
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
    public function testObjectsPublicUrlsCanBeRetrieved($bucketName, $objectPath, $expectedUrl)
    {
        $adapterConfig = [
            'bucket'    => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $fs = new Filesystem($adapter);
        $fs->addPlugin(new GoogleCloudStoragePublicUrlPlugin(['bucket' => $bucketName]));

        $this->assertEquals($expectedUrl, $fs->getUrl($objectPath));
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
