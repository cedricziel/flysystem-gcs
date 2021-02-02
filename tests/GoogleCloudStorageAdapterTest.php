<?php

declare(strict_types=1);

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

    protected function setUp(): void
    {
        $this->project = getenv('GOOGLE_CLOUD_PROJECT');
        $this->bucket = getenv('GOOGLE_CLOUD_BUCKET');
    }

    public function testAnAdapterCanBeCreatedWithMinimalConfiguration(): void
    {
        $minimalConfig = [
            'bucket' => 'test',
            'projectId' => 'test',
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        self::assertInstanceOf(GoogleCloudStorageAdapter::class, $adapter);
    }

    public function testCanListContentsOfADirectory(): void
    {
        $testDirectory = '/'.uniqid('test_', true).'/';
        $minimalConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $config = new Config([]);
        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $contents = $adapter->listContents('/');

        self::assertNotFalse($adapter->createDir($testDirectory, $config));
        self::assertTrue($adapter->has($testDirectory));

        $testContents = $adapter->listContents($testDirectory);

        self::assertTrue($adapter->deleteDir($testDirectory));
        self::assertFalse($adapter->has($testDirectory));
        self::assertFalse($adapter->has(ltrim($testDirectory, '/')));
        self::assertFalse($adapter->has(rtrim(ltrim($testDirectory, '/'), '/')));
    }

    public function testFilesCanBeUploaded(): void
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_content/{$testId}_public_test.jpg";

        $minimalConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $config = new Config([]);
        $adapter->write(
            $destinationPath,
            file_get_contents(__DIR__.'/../res/Auswahl_126.png'),
            $config
        );

        self::assertTrue($adapter->has($destinationPath));

        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PUBLIC);

        // check visibility
        self::assertTrue($adapter->delete($destinationPath));
        self::assertFalse($adapter->has($destinationPath));

        $testId = uniqid('', true);
        $destinationPath = "/test_content/{$testId}_private_test.jpg";

        $config = new Config([]);
        $adapter->write(
            $destinationPath,
            file_get_contents(__DIR__.'/../res/Auswahl_126.png'),
            $config
        );

        self::assertTrue($adapter->has($destinationPath));

        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PUBLIC);
        $adapter->setVisibility($destinationPath, AdapterInterface::VISIBILITY_PRIVATE);

        self::assertTrue($adapter->delete($destinationPath));
        self::assertFalse($adapter->has($destinationPath));
    }

    public function testCanCreateDirectories(): void
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_content-canCreateDirectories-{$testId}";

        $minimalConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $config = new Config([]);
        self::assertNotFalse($adapter->createDir($destinationPath, $config));
        self::assertTrue($adapter->deleteDir($destinationPath));
    }

    public function testAFileCanBeRead(): void
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_testAFileCanBeRead-{$testId}_text.txt";
        $content = 'testAFileCanBeRead';

        $minimalConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        $config = new Config([]);
        $adapter->write($destinationPath, $content, $config);

        self::assertTrue($adapter->has($destinationPath), 'Once writte, the adapter can see the object in GCS');
        self::assertEquals(
            $content,
            $adapter->read($destinationPath)['contents'],
            'The content of the GCS object matches exactly the input'
        );
        self::assertEquals(
            'file',
            $adapter->getMetadata($destinationPath)['type'],
            'The object type is interpreted correctly'
        );
        self::assertEquals(
            'text/plain',
            $adapter->getMimetype($destinationPath)['mimetype'],
            'The mime type is available'
        );
        self::assertEquals(
            \strlen($content),
            $adapter->getSize($destinationPath)['size'],
            'The size from the metadata matches the input'
        );

        self::assertIsInt($adapter->getTimestamp($destinationPath)['timestamp'], 'The object has a timestamp');

        self::assertGreaterThan(
            0,
            $adapter->getTimestamp($destinationPath)['timestamp'],
            'The timestamp from GCS is added to the metadata correctly'
        );
        self::assertTrue($adapter->delete($destinationPath));
        self::assertFalse($adapter->has($destinationPath));
    }

    public function testDeletingNonExistentObjectsWillNotFail(): void
    {
        $minimalConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $minimalConfig);

        self::assertTrue($adapter->delete('no_file_in_storage.txt'));
    }

    public function testPrefixesCanBeUsed(): void
    {
        $testId = uniqid('', true);
        $testPrefix = "my/prefix/testPrefixesCanBeUsed-{$testId}/";

        $simpleConfig = new Config([]);
        $prefixedAdapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
            'prefix' => $testPrefix,
        ];

        $prefixedAdapter = new GoogleCloudStorageAdapter(null, $prefixedAdapterConfig);

        $unprefixedAdapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $unprefixedAdapter = new GoogleCloudStorageAdapter(null, $unprefixedAdapterConfig);

        $path = 'test.txt';
        $contents = 'This is just a simple melody....';
        $prefixedAdapter->write($path, $contents, $simpleConfig);
        self::assertTrue($prefixedAdapter->has($path));
        self::assertEquals($contents, $prefixedAdapter->read($path)['contents']);

        self::assertTrue($prefixedAdapter->has($path));
        self::assertTrue($unprefixedAdapter->has($testPrefix.$path));

        self::assertTrue($prefixedAdapter->delete($path));
    }

    public function testCanBeWrappedWithAFilesystem(): void
    {
        $testId = uniqid('', true);
        $destinationPath = "/test_content-testCanBeWrappedWithAFilesystem-{$testId}/test.txt";

        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);
        $contents = 'This is just a simple melody....';

        $fs->write($destinationPath, $contents);

        $data = $fs->read($destinationPath);

        self::assertEquals($contents, $data);
        self::assertTrue($fs->has($destinationPath));

        self::assertTrue($fs->delete($destinationPath), 'Files can be removed without errors');
        self::assertFalse($fs->has($destinationPath), 'They are gone after the previous operation');
    }

    public function testVisibilityCanBeSetOnWrite(): void
    {
        $testId = uniqid('', true);
        $destinationPathPrivate = "/test_content-testVisibilityCanBeSetOnWrite-{$testId}/test-private.txt";
        $destinationPathPublic = "/test_content-testVisibilityCanBeSetOnWrite-{$testId}/test-public.txt";

        $adapterConfig = [
            'bucket' => $this->bucket,
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

        self::assertEquals($contents, $data);
        self::assertTrue($fs->has($destinationPathPrivate));
        self::assertEquals(AdapterInterface::VISIBILITY_PRIVATE, $fs->getVisibility($destinationPathPrivate));

        // Test pre-setting public visibility
        $fs->write(
            $destinationPathPublic,
            $contents,
            [
                'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
            ]
        );

        $data = $fs->read($destinationPathPublic);

        self::assertEquals($contents, $data);
        self::assertTrue($fs->has($destinationPathPublic));
        self::assertEquals(AdapterInterface::VISIBILITY_PUBLIC, $fs->getVisibility($destinationPathPublic));

        self::assertTrue($fs->delete($destinationPathPrivate));
        self::assertTrue($fs->delete($destinationPathPublic));
    }

    public function testCanUpdateAFile(): void
    {
        $testId = uniqid('', true);
        $destination = "/test_content-testCanUpdateAFile-{$testId}/test.txt";
        $initialContent = 'testCanUpdateAFile';
        $updatedContent = 'testCanUpdateAFile-update';

        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);

        $fs->put($destination, $initialContent);
        self::assertTrue($fs->has($destination));
        self::assertTrue($fs->update($destination, $updatedContent));
        self::assertEquals($updatedContent, $fs->read($destination));
        self::assertTrue($fs->delete($destination));
    }

    public function testCanCopyObject(): void
    {
        $testId = uniqid('', true);
        $destination = "/test_content-testCanCopyObject-{$testId}/test.txt";
        $copyDestination = "/test_content-testCanCopyObject-{$testId}/test-copy.txt";
        $initialContent = 'testCanCopyObject';

        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);

        $fs->put($destination, $initialContent);
        self::assertEquals($initialContent, $fs->read($destination));
        self::assertFalse($fs->has($copyDestination));
        self::assertTrue($fs->copy($destination, $copyDestination));

        self::assertTrue($fs->delete($destination));
        self::assertTrue($fs->delete($copyDestination));
    }

    public function testCanRenameObject(): void
    {
        $testId = uniqid('', true);
        $originalDestination = "/test_content-testCanRenameObject-{$testId}/test.txt";
        $renameDestination = "/test_content-testCanRenameObject-{$testId}/test-rename.txt";
        $initialContent = 'testCanRenameObject';

        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);

        $fs = new Filesystem($adapter);

        $fs->put($originalDestination, $initialContent);
        self::assertEquals($initialContent, $fs->read($originalDestination));
        self::assertFalse($fs->has($renameDestination));
        self::assertTrue($fs->rename($originalDestination, $renameDestination));
        self::assertFalse($fs->has($originalDestination));
        self::assertTrue($fs->has($renameDestination));

        self::assertTrue($fs->delete($renameDestination));
    }

    public function testObjectsCanBeHandledThroughStreams(): void
    {
        $testId = uniqid('', true);
        $originalDestination = "/test_content-testObjectsCanBeHandledThroughStreams-{$testId}/test.txt";
        $initialContent = 'testObjectsCanBeHandledThroughStreams';
        $updatedContent = 'testObjectsCanBeHandledThroughStreamsUpdated';

        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $fs = new Filesystem($adapter);

        // put through stream
        $contentStream = fopen('data://text/plain;base64,'.base64_encode($initialContent), 'r');
        $fs->putStream($originalDestination, $contentStream);

        // read through stream
        self::assertEquals($initialContent, stream_get_contents($fs->readStream($originalDestination)));

        // update through stream
        $updateContentStream = fopen('data://text/plain;base64,'.base64_encode($updatedContent), 'r');
        self::assertTrue($fs->updateStream($originalDestination, $updateContentStream));

        // read updated content through stream
        self::assertEquals($updatedContent, stream_get_contents($fs->readStream($originalDestination)));

        self::assertTrue($fs->delete($originalDestination));
        self::assertFalse($fs->has($originalDestination));

        $contentStream = fopen('data://text/plain;base64,'.base64_encode($initialContent), 'r');
        self::assertTrue($fs->writeStream($originalDestination, $contentStream));
        self::assertEquals($initialContent, stream_get_contents($fs->readStream($originalDestination)));
        self::assertTrue($fs->delete($originalDestination));
        self::assertFalse($fs->has($originalDestination));
    }

    /**
     * @dataProvider urlPrefixDataProvider
     *
     * @param string $urlPrefix
     * @param string $objectPath
     * @param string $expectedUrl
     */
    public function testObjectsPublicUrlsCanUseCustomUrls($urlPrefix, $objectPath, $expectedUrl): void
    {
        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $fs = new Filesystem($adapter);
        $fs->addPlugin(new GoogleCloudStoragePublicUrlPlugin(['url' => $urlPrefix]));

        self::assertEquals($expectedUrl, $fs->getUrl($objectPath));
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
    public function testObjectsPublicUrlsCanBeRetrieved($bucketName, $objectPath, $expectedUrl): void
    {
        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $fs = new Filesystem($adapter);
        $fs->addPlugin(new GoogleCloudStoragePublicUrlPlugin(['bucket' => $bucketName]));

        self::assertEquals($expectedUrl, $fs->getUrl($objectPath));
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

    /**
     * @see https://github.com/cedricziel/flysystem-gcs/issues/12
     *
     * @covers \CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter::getUrl()
     */
    public function testPrefixIsNotAddedTwiceForUrl(): void
    {
        $testId = uniqid('', true);
        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
            'prefix' => sprintf('icon-%s', $testId),
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $url = $adapter->getUrl('test.txt');

        self::assertEquals(sprintf('%s/%s/%s/test.txt', GoogleCloudStorageAdapter::GCS_BASE_URL, $adapterConfig['bucket'], $adapterConfig['prefix']), $url);
    }

    /**
     * @see https://github.com/cedricziel/flysystem-gcs/issues/12
     *
     * @covers \CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter::getUrl()
     */
    public function testUrlCanBeCreated(): void
    {
        $testId = uniqid('', true);
        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $objectName = sprintf('%s/test.txt', sprintf('icon-%s', $testId));
        $url = $adapter->getUrl($objectName);

        self::assertEquals(sprintf('%s/%s/%s', GoogleCloudStorageAdapter::GCS_BASE_URL, $adapterConfig['bucket'], $objectName), $url);
    }

    /**
     * @see https://github.com/cedricziel/flysystem-gcs/issues/11
     *
     * @covers \CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter::has()
     */
    public function testHasWorksCorrectlyForDirectories(): void
    {
        $testId = uniqid('', true);
        $adapterConfig = [
            'bucket' => $this->bucket,
            'projectId' => $this->project,
        ];

        $directoryName = sprintf('%s', sprintf('icon-%s', $testId));

        $adapter = new GoogleCloudStorageAdapter(null, $adapterConfig);
        $fs = new Filesystem($adapter);
        $fs->createDir($directoryName);

        self::assertTrue($fs->has($directoryName));
    }
}
