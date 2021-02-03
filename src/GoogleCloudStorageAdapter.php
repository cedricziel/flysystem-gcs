<?php

declare(strict_types=1);

namespace CedricZiel\FlysystemGcs;

use DateTimeImmutable;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;

/**
 * Flysystem Adapter for Google Cloud Storage.
 * Permissions:
 * Flysystem mostly uses 2 different types of visibility: public and private. This adapter maps those
 * to either grant project-private access or public access. The default is `projectPrivate`
 * For using a more appropriate ACL for a specific use-case, the.
 *
 * @see FilesystemAdapter
 *
 * @todo Use PathPrefixer
 * @todo implement visibility
 */
class GoogleCloudStorageAdapter implements FilesystemAdapter
{
    /**
     * ACL that grants access to everyone on the project.
     */
    public const GCS_VISIBILITY_PROJECT_PRIVATE = 'projectPrivate';

    /**
     * ACL that grants public read access to everyone.
     */
    public const GCS_VISIBILITY_PUBLIC_READ = 'publicRead';

    /**
     * Public URL prefix.
     */
    public const GCS_BASE_URL = 'https://storage.googleapis.com';

    /**
     * @var Bucket
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var StorageClient
     */
    private $storageClient;

    /**
     * @var ExtensionMimeTypeDetector
     */
    private $mimeTypeDetector;

    /**
     * @var PathPrefixer
     */
    private $pathPrefixer;

    /**
     * @var string|null path prefix
     */
    protected $pathPrefix;

    /**
     * Either pass in a custom client, or have one created for you from the config
     * array.
     * Minimal $config array should be:
     * ```
     * [
     *  'bucket' => 'my-bucket-name',
     * ]
     * ```
     * Full options:
     * ```
     * [
     *  'bucket' => 'my-bucket-name',
     * ]
     * ```.
     */
    public function __construct(StorageClient $storageClient = null, array $config = [])
    {
        if ($storageClient) {
            $this->storageClient = $storageClient;
        } else {
            $this->storageClient = new StorageClient($config);
        }

        $this->bucket = $this->storageClient->bucket($config['bucket']);

        $this->mimeTypeDetector = new ExtensionMimeTypeDetector();
        // The adapter can optionally use a prefix. If it's not set, the bucket root is used
        if (\array_key_exists('prefix', $config) && $config['prefix'] !== null) {
            $this->pathPrefixer = new PathPrefixer($config['prefix']);
            $this->pathPrefix = $config['prefix'];
        } else {
            $this->pathPrefixer = new PathPrefixer('');
            $this->pathPrefix = '';
        }

        $this->prepareBaseUrl($config);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return void false on failure file meta data on success
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeObject($path, $contents, $config);
    }

    /**
     * @param resource $contents
     *
     * @throws UnableToWriteFile
     * @throws FilesystemException
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->writeObject($path, $contents, $config);
    }

    /**
     * @throws UnableToMoveFile
     * @throws FilesystemException
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $source = $this->pathPrefixer->prefixPath($source);
        $destination = $this->pathPrefixer->prefixPath($destination);

        $sourceStorageObject = $this->bucket->object($source);
        if (!$sourceStorageObject->exists()) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }

        $this->copy($source, $destination, new Config([]));
        $this->delete($source);
    }

    /**
     * @throws UnableToCopyFile
     * @throws FilesystemException
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $sourcePath = $this->pathPrefixer->prefixPath($source);
        $destinationPath = $this->pathPrefixer->prefixPath($destination);

        $sourceObject = $this->bucket->object($sourcePath);

        $sourceVisibility = $this->visibility($source)->visibility();
        $destinationOptions = [
            'name' => $destinationPath,
        ];

        $sourceObject->copy($this->bucket, $destinationOptions);
        $this->setVisibility($destination, $sourceVisibility);
    }

    /**
     * @throws UnableToDeleteFile
     * @throws FilesystemException
     */
    public function delete(string $path): void
    {
        $path = $this->pathPrefixer->prefixPath($path);
        $object = $this->bucket->object($path);

        if (false === $object->exists()) {
            UnableToDeleteFile::atLocation($path);
        }

        $object->delete();

        if ($object->exists()) {
            UnableToDeleteFile::atLocation($path);
        }
    }

    /**
     * @throws UnableToDeleteDirectory
     * @throws FilesystemException
     */
    public function deleteDirectory(string $path): void
    {
        $dirname = rtrim($path, '/') . '/';

        $dirname = $this->pathPrefixer->prefixPath($dirname);

        $this->bucket->objects(['prefix' => $dirname]);

        $storageObject = $this->bucket->object($dirname);
        if (!$storageObject->exists()) {
            throw UnableToDeleteDirectory::atLocation($dirname, 'Directory doesnt exist');
        }

        $storageObject->delete();

        $storageObject = $this->bucket->object($dirname);
        if ($storageObject->exists()) {
            throw UnableToDeleteDirectory::atLocation($path, 'Unable to delete');
        }
    }

    /**
     * @throws UnableToCreateDirectory
     * @throws FilesystemException
     */
    public function createDirectory(string $path, Config $config): void
    {
        $path = $this->pathPrefixer->prefixPath($path);
        $path = rtrim($path, '/') . '/';

        $this->bucket->upload('', ['name' => $path]);
    }

    /**
     * @throws InvalidVisibilityProvided
     * @throws FilesystemException
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $path = $this->pathPrefixer->prefixPath($path);
        $object = $this->bucket->object($path);

        if (!$object->exists()) {
            throw UnableToSetVisibility::atLocation($path, 'Object doesnt exist');
        }

        switch (true) {
            case Visibility::PUBLIC === $visibility:
                $object->acl()->add('allUsers', Acl::ROLE_READER);
                break;
            case Visibility::PRIVATE === $visibility:
                $object->acl()->delete('allUsers');
                break;
            default:
                // invalid value
                break;
        }

        $object->reload();
    }

    /**
     * @throws FilesystemException
     */
    public function fileExists(string $path): bool
    {
        $path = $this->pathPrefixer->prefixPath($path);
        $storageObject = $this->bucket->object($path);

        if ($storageObject->exists()) {
            return true;
        }

        // flysystem strips trailing slashes so we need to check
        // for directory objects
        return $this->bucket->object(sprintf('%s/', $path))->exists();
    }

    /**
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function read(string $path): string
    {
        $path = $this->pathPrefixer->prefixPath($path);

        $object = $this->bucket->object($path);
        if (!$object->exists()) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $object->downloadAsString();
    }

    /**
     * @return iterable<StorageAttributes>
     *
     * @throws FilesystemException
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $directory = $this->pathPrefixer->prefixPath($path);

        $objects = $this->bucket->objects(
            [
                'prefix' => $directory,
            ]
        );

        /** @var StorageObject $gcsObject */
        foreach ($objects as $gcsObject) {
            if (false === $deep) {
                // dont list nested objects
                $name = $gcsObject->name();
                $strippedName = substr($name, strlen($directory) + 1);
                if (strpos($strippedName, '/') !== strlen($strippedName) - 1) {
                    continue;
                }
            }

            yield $this->convertObjectInfo($gcsObject);
        }
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return StorageAttributes
     */
    public function getMetadata($path): StorageAttributes
    {
        $path = $this->pathPrefixer->prefixPath($path);

        $object = $this->bucket->object($path);

        if (!$object->exists()) {
            // todo: throw
            // throw UnableToRetrieveMetadata::create($path, null);
        }

        return $this->convertObjectInfo($object);
    }

    /**
     * Get all the meta data of a file.
     *
     * @param string $path
     *
     * @return StorageAttributes
     */
    public function getFileMetadata($path): FileAttributes
    {
        $object = $this->bucket->object($path);
        if (!$object->exists()) {
            throw UnableToRetrieveMetadata::create($path, FileAttributes::ATTRIBUTE_TYPE);
        }

        $detectMimeTypeFromFile = $this->mimeTypeDetector->detectMimeTypeFromFile($path);

        $objectInfo = $this->convertObjectInfo($object);
        if ($objectInfo->isDir()) {
            // todo: throw
        }

        return $objectInfo;
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function fileSize(string $path): FileAttributes
    {
        $path = $this->pathPrefixer->prefixPath($path);

        $storageObject = $this->bucket->object($path);
        if (!$storageObject->exists()) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        $storageAttributes = $this->convertObjectInfo($storageObject);
        if ($storageAttributes->isDir()) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return $this->getFileMetadata($path);
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function mimeType(string $path): FileAttributes
    {
        $path = $this->pathPrefixer->prefixPath($path);

        $storageObject = $this->bucket->object($path);
        if (!$storageObject->exists()) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        $mimeType = $this->mimeTypeDetector->detectMimeTypeFromFile($path);
        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, error_get_last()['message'] ?? '');
        }

        return new FileAttributes($path, null, null, null, $mimeType);
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function lastModified(string $path): FileAttributes
    {
        $path = $this->pathPrefixer->prefixPath($path);

        $storageObject = $this->bucket->object($path);
        if (!$storageObject->exists()) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return $this->getFileMetadata($path);
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function visibility(string $path): FileAttributes
    {
        $prefixedPath = $this->pathPrefixer->prefixPath($path);

        $storageObject = $this->bucket->object($prefixedPath);
        if (!$storageObject->exists()) {
            throw UnableToRetrieveMetadata::visibility($prefixedPath);
        }

        try {
            $allUsersAcl = $storageObject->acl()->get(['entity' => 'allUsers']);

            if (Acl::ROLE_READER === $allUsersAcl['role']) {
                return new FileAttributes($prefixedPath, null, Visibility::PUBLIC);
            }
        } catch (NotFoundException $e) {
            // no acl entry, it's fine
        }

        return new FileAttributes($prefixedPath, null, Visibility::PRIVATE);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        return sprintf('%s/%s', rtrim($this->baseUrl, '/'), ltrim($path, '/'));
    }

    /**
     * @param $object StorageObject
     *
     * @return StorageAttributes
     */
    protected function convertObjectInfo(StorageObject $object): StorageAttributes
    {
        $identity = $object->identity();
        $objectInfo = $object->info();
        $objectName = $identity['object'];

        // determine whether it's a file or a directory
        $type = StorageAttributes::TYPE_FILE;
        $objectNameLength = \strlen($objectName);
        if (strpos($objectName, '/', $objectNameLength - 1)) {
            $type = StorageAttributes::TYPE_DIRECTORY;
        }

        $normalizedObjectInfo = [
            StorageAttributes::ATTRIBUTE_TYPE => $type,
            StorageAttributes::ATTRIBUTE_PATH => $this->pathPrefixer->stripPrefix($objectName),
            StorageAttributes::ATTRIBUTE_FILE_SIZE => 0,
            StorageAttributes::ATTRIBUTE_MIME_TYPE => null,
            StorageAttributes::ATTRIBUTE_LAST_MODIFIED => null,
            StorageAttributes::ATTRIBUTE_EXTRA_METADATA => [],
        ];

        $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_LAST_MODIFIED] = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s+', $objectInfo['updated'])->getTimestamp();

        if ('file' === $type) {
            $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_FILE_SIZE] = (int)$objectInfo['size'];
            $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_MIME_TYPE] = $objectInfo['contentType'] ?? null;
        }

        $path1 = $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_PATH];

        /** @todo set real visibility */
        $visibility = null;
        $lastModified = $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_LAST_MODIFIED];
        $size = $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_FILE_SIZE];
        $mimeType = $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_MIME_TYPE];

        return $normalizedObjectInfo[StorageAttributes::ATTRIBUTE_TYPE] === StorageAttributes::TYPE_DIRECTORY
            ? new DirectoryAttributes(rtrim($path1, '/'), $visibility, $lastModified)
            : new FileAttributes($path1, $size, $visibility, $lastModified, $mimeType);
    }

    /**
     * Converts flysystem specific config to options for the underlying API client.
     *
     * @param $config Config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = [];

        if ($config->get('visibility') !== null) {
            switch (true) {
                case Visibility::PUBLIC === $config->get('visibility'):
                    $options['predefinedAcl'] = static::GCS_VISIBILITY_PUBLIC_READ;
                    break;
                case Visibility::PRIVATE === $config->get('visibility'):
                default:
                    $options['predefinedAcl'] = static::GCS_VISIBILITY_PROJECT_PRIVATE;
                    break;
            }
        }

        return $options;
    }

    /**
     * Writes an object to the current.
     *
     * @param string $path
     * @param string|resource $contents
     *
     * @return array
     */
    protected function writeObject($path, $contents, Config $config)
    {
        $path = $this->pathPrefixer->prefixPath($path);
        $metadata = [
            'name' => $path,
        ];

        $metadata += $this->getOptionsFromConfig($config);

        $uploadedObject = $this->bucket->upload($contents, $metadata);
    }

    /**
     * @param array $config
     */
    protected function prepareBaseUrl($config = []): void
    {
        if (\array_key_exists('url', $config)) {
            $this->baseUrl = $config['url'];

            return;
        }

        $pieces = [
            static::GCS_BASE_URL,
            $this->bucket->name(),
            $this->pathPrefix,
        ];
        $pieces = array_filter($pieces);
        $this->baseUrl = implode('/', $pieces);
    }

    /**
     * @return resource
     *
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function readStream(string $path)
    {
        $path = $this->pathPrefixer->prefixPath($path);

        $storageObject = $this->bucket->object($path);
        if (!$storageObject->exists()) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $storageObject->downloadAsStream()->detach();
    }
}
