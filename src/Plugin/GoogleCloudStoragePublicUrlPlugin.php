<?php

namespace CedricZiel\FlysystemGcs\Plugin;

use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use League\Flysystem\Plugin\AbstractPlugin;

/**
 * Flysystem plugin to retrieve the public url of an object.
 *
 * Example that references a bucket name:
 * ```
 * $adapter = new GoogleCloudStorageAdapter(..);
 * $filesystem = new Filesystem($adapter);
 *
 * $config = ['bucket' => 'my-application-bucket'];
 * $filesystem->addPlugin(new GoogleCloudStoragePublicUrlPlugin($config));
 *
 * $publicUrl = $filesystem->getUrl($path);
 * ```
 *
 * @package CedricZiel\FlysystemGcs\Plugin
 */
class GoogleCloudStoragePublicUrlPlugin extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $urlPrefix;

    /**
     * This url is prepended to an objects path
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        if (array_key_exists('url', $config)) {
            $this->urlPrefix = $config['url'];
        } elseif (array_key_exists('bucket', $config)) {
            $this->urlPrefix = sprintf('%s/%s', GoogleCloudStorageAdapter::GCS_BASE_URL, $config['bucket']);
        } else {
            throw new \InvalidArgumentException(__CLASS__.': Neither a bucket, nor a url was given');
        }
    }

    /**
     * Get the method name.
     *
     * @return string
     */
    public function getMethod()
    {
        return 'getUrl';
    }

    /**
     * Executes the plugin.
     *
     * @param string null $path
     *
     * @return mixed
     */
    public function handle($path = null)
    {
        $path = ltrim($path, '/');
        $cleanPrefix = rtrim($this->urlPrefix, '/');

        return sprintf('%s/%s', $cleanPrefix, $path);
    }
}
