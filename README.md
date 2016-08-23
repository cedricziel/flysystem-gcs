# flysystem-gcs - Flysystem Adapter for Google Cloud Storage

Flysystem Adapter for Google cloud storage using the gcloud PHP library

## How-To

```php
use CedricZiel\FlysystemGcs\GoogleCloudStorageAdapter;
use League\Flysystem\Filesystem;

$storageClientOptions = [
    'projectId' => 'my-project-id',
    'bucket' => 'my-project-bucket.appspot.com'
];
$adapter = new GoogleCloudStorageAdapter($storageClientOptions);

$filesystem = new Filesystem($adapter);
```

## ToDo

* allow path prefixes (subdirectories as mount)
* get copy and rename operations right

## License

MIT
