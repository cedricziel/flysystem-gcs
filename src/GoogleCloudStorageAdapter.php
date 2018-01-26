<?php

namespace CedricZiel\FlysystemGcs;

use Google\Cloud\Exception\NotFoundException;
use Google\Cloud\Storage\Acl;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;

/**
 * Flysystem Adapter for Google Cloud Storage.
 * Permissions:
 * Flysystem mostly uses 2 different types of visibility: public and private. This adapter maps those
 * to either grant project-private access or public access. The default is `projectPrivate`
 * For using a more appropriate ACL for a specific use-case, the
 *
 * @see AdapterInterface
 */
class GoogleCloudStorageAdapter extends AbstractAdapter
{
    use StreamedReadingTrait;

    /**
     * ACL that grants access to everyone on the project
     */
    const GCS_VISIBILITY_PROJECT_PRIVATE = 'projectPrivate';

    /**
     * ACL that grants public read access to everyone
     */
    const GCS_VISIBILITY_PUBLIC_READ = 'publicRead';

    /**
     * Public URL prefix
     */
    const GCS_BASE_URL = 'https://storage.googleapis.com';

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
     * ```
     *
     * @param StorageClient|null $storageClient
     * @param array              $config
     */
    public function __construct(StorageClient $storageClient = null, array $config = [])
    {
        if ($storageClient) {
            $this->storageClient = $storageClient;
        } else {
            $this->storageClient = new StorageClient($config);
        }

        $this->bucket = $this->storageClient->bucket($config['bucket']);

        // The adapter can optionally use a prefix. If it's not set, the bucket root is used
        if (array_key_exists('prefix', $config)) {
            $this->setPathPrefix($config['prefix']);
        } else {
            $this->setPathPrefix('');
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
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        return $this->writeObject($path, $contents, $config);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->writeObject($path, $resource, $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->writeObject($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeObject($path, $resource, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $statusCopy = $this->copy($path, $newpath);
        $statusRemoveOld = $this->delete($path);

        return $statusCopy && $statusRemoveOld;
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        $this->bucket
            ->object($path)
            ->copy($this->bucket, ['name' => $newpath]);

        return $this->bucket->object($newpath)->exists();
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);
        $object = $this->bucket->object($path);

        if (false === $object->exists()) {
            return true;
        }

        $object->delete();

        return !$object->exists();
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $dirname = rtrim($dirname, '/').'/';

        if (false === $this->has($dirname)) {
            return false;
        }

        $dirname = $this->applyPathPrefix($dirname);

        $this->bucket->object($dirname)->delete();

        return !$this->bucket->object($dirname)->exists();
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $path = $this->applyPathPrefix($dirname);
        $path = rtrim($path, '/').'/';

        $object = $this->bucket->upload('', ['name' => $path]);

        return $this->convertObjectInfo($object);
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $path = $this->applyPathPrefix($path);
        $object = $this->bucket->object($path);

        switch (true) {
            case $visibility === AdapterInterface::VISIBILITY_PUBLIC:
                $object->acl()->add('allUsers', Acl::ROLE_READER);
                break;
            case $visibility === AdapterInterface::VISIBILITY_PRIVATE:
                $object->acl()->delete('allUsers');
                break;
            default:
                // invalid value
                break;
        }

        $object->reload();

        return $this->convertObjectInfo($object);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $path = $this->applyPathPrefix($path);
        $object = $this->bucket->object($path);

        return $object->exists();
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $path = $this->applyPathPrefix($path);

        $object = $this->bucket->object($path);

        $contents = $object->downloadAsString();

        return compact('path', 'contents');
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->applyPathPrefix($directory);

        $objects = $this->bucket->objects(
            [
                'prefix' => $directory,
            ]
        );

        $contents = [];
        foreach ($objects as $apiObject) {
            if (null !== $apiObject) {
                $contents[] = $this->convertObjectInfo($apiObject);
            }
        }

        // if directory, skip the directory object
        // if directory, truncate prefix + delimiter
        if ('' !== $directory) {
            foreach ($contents as $idx => $objectInfo) {
                if ('dir' === $objectInfo['type'] && $objectInfo['path'] === $directory) {
                    $contents[$idx] = false;
                }
            }
        }

        return array_filter($contents);
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);

        $object = $this->bucket->object($path);

        if (!$object->exists()) {
            return false;
        }

        return $this->convertObjectInfo($object);
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        $path = $this->applyPathPrefix($path);

        try {
            $allUsersAcl = $this->bucket->object($path)->acl()->get(['entity' => 'allUsers']);

            if ($allUsersAcl['role'] === Acl::ROLE_READER) {
                return ['path' => $path, 'visibility' => AdapterInterface::VISIBILITY_PUBLIC];
            }
        } catch (NotFoundException $e) {
            return ['path' => $path, 'visibility' => AdapterInterface::VISIBILITY_PRIVATE];
        }

        return ['path' => $path, 'visibility' => AdapterInterface::VISIBILITY_PRIVATE];
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        $path = $this->applyPathPrefix($path);

        return implode('/', [$this->baseUrl, $path]);
    }

    /**
     * @param $object StorageObject
     *
     * @return array
     */
    protected function convertObjectInfo($object)
    {
        $identity = $object->identity();
        $objectInfo = $object->info();
        $objectName = $identity['object'];

        // determine whether it's a file or a directory
        $type = 'file';
        $objectNameLength = strlen($objectName);
        if (strpos($objectName, '/', $objectNameLength - 1)) {
            $type = 'dir';
        }

        $normalizedObjectInfo = [
            'type' => $type,
            'path' => $objectName,
        ];

        // timestamp
        $datetime = \DateTime::createFromFormat('Y-m-d\TH:i:s+', $objectInfo['updated']);
        $normalizedObjectInfo['timestamp'] = $datetime->getTimestamp();

        // size when file
        if ($type === 'file') {
            $normalizedObjectInfo['size'] = $objectInfo['size'];
            $normalizedObjectInfo['mimetype'] = isset($objectInfo['contentType']) ? $objectInfo['contentType'] : null;
        }

        return $normalizedObjectInfo;
    }

    /**
     * Converts flysystem specific config to options for the underlying API client
     *
     * @param $config Config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = [];

        if ($config->has('visibility')) {
            switch (true) {
                case $config->get('visibility') === AdapterInterface::VISIBILITY_PUBLIC:
                    $options['predefinedAcl'] = static::GCS_VISIBILITY_PUBLIC_READ;
                    break;
                case $config->get('visibility') === AdapterInterface::VISIBILITY_PRIVATE:
                default:
                    $options['predefinedAcl'] = static::GCS_VISIBILITY_PROJECT_PRIVATE;
                    break;
            }
        }

        return $options;
    }

    /**
     * Writes an object to the current
     *
     * @param string          $path
     * @param string|resource $contents
     * @param Config          $config
     *
     * @return array
     */
    protected function writeObject($path, $contents, Config $config)
    {
        $path = $this->applyPathPrefix($path);
        $metadata = [
            'name' => $path,
        ];

        $metadata += $this->getOptionsFromConfig($config);

        $uploadedObject = $this->bucket->upload($contents, $metadata);
        $uploadedObject->reload();

        return $this->convertObjectInfo($uploadedObject);
    }

    /**
     * @param array $config
     */
    protected function prepareBaseUrl($config = [])
    {
        if (array_key_exists('url', $config)) {
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
}
