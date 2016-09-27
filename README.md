# Drupal Flysystem integration for OpenStack Swift

## Example settings.php configuration

```php
$schemes = []; // Including any other schemes
$schemes['swift'] = [
  'driver' => 'swift',
  'config' => [
    'authUrl' => '{authUrl}',
    'region'  => '{region}',
    'user'    => [
      'id'       => '{userId}',
      'password' => '{password}'
    ],
    'container' => '{containerName}',
    'scope'   => ['project' => ['id' => '{projectId}']], // Optional
  ],
];
$settings['flysystem'] = $schemes;
```