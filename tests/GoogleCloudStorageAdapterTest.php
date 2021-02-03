<?php

declare(strict_types=1);

namespace CedricZiel\FlysystemGcs\Tests;

use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Visibility;
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

        $contents = $adapter->listContents('/', false);

        $adapter->createDirectory($testDirectory, $config);
        self::assertTrue($adapter->fileExists($testDirectory));

        $testContents = $adapter->listContents($testDirectory, false);

        $adapter->deleteDirectory($testDirectory);

        self::assertFalse($adapter->fileExists($testDirectory));
        self::assertFalse($adapter->fileExists(ltrim($testDirectory, '/')));
        self::assertFalse($adapter->fileExists(rtrim(ltrim($testDirectory, '/'), '/')));
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

        self::assertTrue($adapter->fileExists($destinationPath));

        $adapter->setVisibility($destinationPath, Visibility::PUBLIC);

        // check visibility
        $adapter->delete($destinationPath);
        self::assertFalse($adapter->fileExists($destinationPath));

        $testId = uniqid('', true);
        $destinationPath = "/test_content/{$testId}_private_test.jpg";

        $config = new Config([]);
        $adapter->write(
            $destinationPath,
            file_get_contents(__DIR__.'/../res/Auswahl_126.png'),
            $config
        );

        self::assertTrue($adapter->fileExists($destinationPath));

        $adapter->setVisibility($destinationPath, Visibility::PUBLIC);
        $adapter->setVisibility($destinationPath, Visibility::PRIVATE);

        $adapter->delete($destinationPath);
        self::assertFalse($adapter->fileExists($destinationPath));
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

        self::assertTrue($adapter->fileExists($destinationPath), 'Once writte, the adapter can see the object in GCS');
        self::assertEquals(
            $content,
            $adapter->read($destinationPath),
            'The content of the GCS object matches exactly the input'
        );
        self::assertEquals(
            'text/plain',
            $adapter->mimeType($destinationPath)->mimeType(),
            'The mime type is available'
        );
        self::assertEquals(
            \strlen($content),
            $adapter->fileSize($destinationPath)->fileSize(),
            'The size from the metadata matches the input'
        );

        self::assertIsInt($adapter->lastModified($destinationPath)->lastModified(), 'The object has a timestamp');

        self::assertGreaterThan(
            0,
            $adapter->lastModified($destinationPath)->lastModified(),
            'The timestamp from GCS is added to the metadata correctly'
        );
        $adapter->delete($destinationPath);
        self::assertFalse($adapter->fileExists($destinationPath));
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
        self::assertTrue($prefixedAdapter->fileExists($path));
        self::assertEquals($contents, $prefixedAdapter->read($path));

        self::assertTrue($prefixedAdapter->fileExists($path));
        self::assertTrue($unprefixedAdapter->fileExists($testPrefix.$path));

        $prefixedAdapter->delete($path);
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
        self::assertTrue($fs->fileExists($destinationPath));

        $fs->delete($destinationPath);

        self::assertFalse($fs->fileExists($destinationPath), 'They are gone after the previous operation');
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
                'visibility' => Visibility::PRIVATE,
            ]
        );

        $data = $fs->read($destinationPathPrivate);

        self::assertEquals($contents, $data);
        self::assertTrue($fs->fileExists($destinationPathPrivate));
        self::assertEquals(Visibility::PRIVATE, $fs->visibility($destinationPathPrivate));

        // Test pre-setting public visibility
        $fs->write(
            $destinationPathPublic,
            $contents,
            [
                'visibility' => Visibility::PUBLIC,
            ]
        );

        $data = $fs->read($destinationPathPublic);

        self::assertEquals($contents, $data);
        self::assertTrue($fs->fileExists($destinationPathPublic));
        self::assertEquals(Visibility::PUBLIC, $fs->visibility($destinationPathPublic));

        $fs->delete($destinationPathPrivate);
        $fs->delete($destinationPathPublic);
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

        $fs->write($destination, $initialContent);
        self::assertTrue($fs->fileExists($destination));

        $fs->write($destination, $updatedContent);

        self::assertEquals($updatedContent, $fs->read($destination));
        $fs->delete($destination);
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

        $fs->write($destination, $initialContent);
        self::assertEquals($initialContent, $fs->read($destination));
        self::assertFalse($fs->fileExists($copyDestination));

        $fs->copy($destination, $copyDestination);

        $fs->delete($destination);
        $fs->delete($copyDestination);
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

        $fs->write($originalDestination, $initialContent);
        self::assertEquals($initialContent, $fs->read($originalDestination));
        self::assertFalse($fs->fileExists($renameDestination));

        $fs->move($originalDestination, $renameDestination, []);

        self::assertFalse($fs->fileExists($originalDestination));
        self::assertTrue($fs->fileExists($renameDestination));

        $fs->delete($renameDestination);
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
        $fs->writeStream($originalDestination, $contentStream);

        // read through stream
        self::assertEquals($initialContent, stream_get_contents($fs->readStream($originalDestination)));

        // update through stream
        $updateContentStream = fopen('data://text/plain;base64,'.base64_encode($updatedContent), 'r');
        $fs->writeStream($originalDestination, $updateContentStream);

        // read updated content through stream
        self::assertEquals($updatedContent, stream_get_contents($fs->readStream($originalDestination)));

        $fs->delete($originalDestination);
        self::assertFalse($fs->fileExists($originalDestination));

        $contentStream = fopen('data://text/plain;base64,'.base64_encode($initialContent), 'r');
        $fs->writeStream($originalDestination, $contentStream);
        self::assertEquals($initialContent, stream_get_contents($fs->readStream($originalDestination)));
        $fs->delete($originalDestination);
        self::assertFalse($fs->fileExists($originalDestination));
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
     * @covers \CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter::fileExists()
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
        $fs->createDirectory($directoryName);

        self::assertTrue($fs->fileExists($directoryName));
    }
}
