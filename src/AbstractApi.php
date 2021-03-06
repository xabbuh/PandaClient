<?php

/*
 * This file is part of the XabbuhPandaClient package.
 *
 * (c) Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Xabbuh\PandaClient;

use Xabbuh\PandaClient\Api\Account;

/**
 * Definition of an algorithm that needs to be implemented to bootstrap an API
 * instance.
 *
 * A concrete implementation must provide implementations for the factory methods
 * specified by this AbstractApi. One factory for each of the components used by
 * this API must be implemented. The library provides a {@link Api default Api}.
 * Custom Api implementations can be built to replace any component with a custom
 * implementation.
 *
 * Basically, the user constructs an Api object by passing a config array to
 * its constructor. This configuration should look like this:
 *
 * - accounts:
 *   - an identifier
 *     - access_key: your access key
 *     - secret_key: your secret key
 *     - api_host: the API host to use
 * - clouds:
 *   - an identifier
 *     - id: your cloud id
 *     - account: one of your account identifiers as configured above
 *
 * A sample config can look like this:
 *
 * <code>
 * $config = array(
 *     'accounts' => array(
 *         'default' => array(
 *             'access_key' => ...,
 *             'secret_key' => ...,
 *             'api_host'   => 'api.pandastream.com',
 *         ),
 *     ),
 *     'clouds' => array(
 *         'default' => array(
 *             'id'      => ...,
 *             'account' => 'default',
 *         ),
 *     ),
 * );
 * </code>
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 */
abstract class AbstractApi
{
    /**
     * @var \Xabbuh\PandaClient\Transformer\TransformerRegistryInterface
     */
    protected $transformers;

    /**
     * @var \Xabbuh\PandaClient\Api\AccountManagerInterface
     */
    protected $accountManager;

    /**
     * @var \Xabbuh\PandaClient\Api\CloudManagerInterface
     */
    protected $cloudManager;

    final public function __construct(array $config)
    {
        // create the transformation layer
        $this->transformers = $this->createTransformerRegistry();
        $this->transformers->setCloudTransformer($this->createCloudTransformer());
        $this->transformers->setEncodingTransformer($this->createEncodingTransformer());
        $this->transformers->setNotificationsTransformer($this->createNotificationsTransformer());
        $this->transformers->setProfileTransformer($this->createProfileTransformer());
        $this->transformers->setVideoTransformer($this->createVideoTransformer());

        // register the accounts
        $this->accountManager = $this->createAccountManager();
        $this->accountManager->setDefaultAccount(
            isset($config['default_account']) ? $config['default_account'] : 'default'
        );
        $this->processAccountConfig($config);

        // register the clouds
        $this->cloudManager = $this->createCloudManager();
        $this->cloudManager->setDefaultCloud(
            isset($config['default_cloud']) ? $config['default_cloud'] : 'default'
        );
        $this->processCloudConfig($config);
    }

    /**
     * @param array $config
     *
     * @throws \InvalidArgumentException
     */
    protected function processAccountConfig(array $config)
    {
        if (!isset($config['accounts']) || !is_array($config['accounts'])) {
            throw new \InvalidArgumentException('No account configuration given.');
        }

        foreach ($config['accounts'] as $name => $accountConfig) {
            $this->validateMandatoryOptions($accountConfig, 'account', $name, array('access_key', 'secret_key', 'api_host'));

            $this->accountManager->registerAccount(
                $name,
                $this->createAccount(
                    $accountConfig['access_key'],
                    $accountConfig['secret_key'],
                    $accountConfig['api_host']
                )
            );
        }
    }

    /**
     * @param array $config
     *
     * @throws \InvalidArgumentException
     */
    protected function processCloudConfig(array $config)
    {
        if (!isset($config['clouds']) || !is_array($config['clouds'])) {
            throw new \InvalidArgumentException('No cloud configuration given.');
        }

        foreach ($config['clouds'] as $name => $cloudConfig) {
            $this->validateMandatoryOptions($cloudConfig, 'cloud', $name, array('id', 'account'));

            try {
                $account = $this->accountManager->getAccount($cloudConfig['account']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid account %s for cloud %s', $name, $cloudConfig['account'])
                );
            }

            $httpClient = $this->createHttpClient($account, $cloudConfig['id']);
            $cloud = $this->createCloud();
            $cloud->setHttpClient($httpClient);
            $cloud->setTransformers($this->transformers);
            $this->cloudManager->registerCloud($name, $cloud);
        }
    }

    /**
     * Creates the {@link AccountManager} that manages the configured accounts.
     *
     * @return \Xabbuh\PandaClient\Api\AccountManagerInterface
     */
    abstract protected function createAccountManager();

    /**
     * Creates an {@link Account} for the given configuration.
     *
     * @param string $accessKey The access key
     * @param string $secretKey The secret key
     * @param string $apiHost   The API host
     *
     * @return \Xabbuh\PandaClient\Api\Account The created account
     */
    protected function createAccount($accessKey, $secretKey, $apiHost)
    {
        return new Account($accessKey, $secretKey, $apiHost);
    }

    /**
     * Creates the {@link CloudManager} that manages the configured clouds.
     *
     * @return \Xabbuh\PandaClient\Api\CloudManagerInterface
     */
    abstract protected function createCloudManager();

    /**
     * Creates the {@link HttpClientInterface HttpClient} that performs the HTTP
     * requests.
     *
     * @param Account $account Account authentication data
     * @param string  $cloudId Id of the cloud to access
     *
     * @return \Xabbuh\PandaClient\Api\HttpClientInterface
     */
    abstract protected function createHttpClient(Account $account, $cloudId);

    /**
     * Creates a {@link Cloud} instances that maps Panda API REST endpoints to
     * method calls.
     *
     * @return \Xabbuh\PandaClient\Api\CloudInterface
     */
    abstract protected function createCloud();

    /**
     * Creates the {@link TransformerRegistryInterface registry} that manages
     * the keeps track of the different transformers.
     *
     * @return \Xabbuh\PandaClient\Transformer\TransformerRegistryInterface
     */
    abstract protected function createTransformerRegistry();

    /**
     * Creates a transformer that transforms between the native API format and
     * {@link Cloud} instances an vice versa.
     *
     * @return \Xabbuh\PandaClient\Transformer\CloudTransformerInterface
     */
    abstract protected function createCloudTransformer();

    /**
     * Creates a transformer that transforms between the native API format and
     * {@link Encoding} instances an vice versa.
     *
     * @return \Xabbuh\PandaClient\Transformer\EncodingTransformerInterface
     */
    abstract protected function createEncodingTransformer();

    /**
     * Creates a transformer that transforms between the native API format and
     * {@link Notifications} instances an vice versa.
     *
     * @return \Xabbuh\PandaClient\Transformer\NotificationsTransformerInterface
     */
    abstract protected function createNotificationsTransformer();

    /**
     * Creates a transformer that transforms between the native API format and
     * {@link Profile} instances an vice versa.
     *
     * @return \Xabbuh\PandaClient\Transformer\ProfileTransformerInterface
     */
    abstract protected function createProfileTransformer();

    /**
     * Creates a transformer that transforms between the native API format and
     * {@link Video} instances an vice versa.
     *
     * @return \Xabbuh\PandaClient\Transformer\VideoTransformerInterface
     */
    abstract protected function createVideoTransformer();

    /**
     * Returns the created {@link AccountManagerInterface AccountManager}.
     *
     * @return \Xabbuh\PandaClient\Api\AccountManagerInterface
     */
    public function getAccountManager()
    {
        return $this->accountManager;
    }

    /**
     * Returns the created {@link CloudManagerInterface CloudManager}.
     *
     * @return \Xabbuh\PandaClient\Api\CloudManagerInterface
     */
    public function getCloudManager()
    {
        return $this->cloudManager;
    }

    /**
     * Returns a {@link Cloud} by its configured name.
     *
     * @param string $name The configured name of the Cloud
     *
     * @return \Xabbuh\PandaClient\Api\CloudInterface
     */
    public function getCloud($name)
    {
        return $this->cloudManager->getCloud($name);
    }

    /**
     * Creates a cloud.
     *
     * @param string $accessKey The access key
     * @param string $secretKey The secret key
     * @param string $apiHost   The api host
     * @param string $cloudId   The cloud id
     *
     * @return \Xabbuh\PandaClient\Api\CloudInterface The cloud
     */
    public static function getCloudInstance($accessKey, $secretKey, $apiHost, $cloudId)
    {
        $config = array(
            'accounts' => array(
                'default' => array(
                    'access_key' => $accessKey,
                    'secret_key' => $secretKey,
                    'api_host' => $apiHost,
                ),
            ),
            'clouds' => array(
                'default' => array(
                    'id' => $cloudId,
                    'account' => 'default',
                )
            ),
        );

        /** @var \Xabbuh\PandaClient\AbstractApi $api */
        $api = new static($config);

        return $api->getCloud('default');
    }

    private function validateMandatoryOptions(array $config, $section, $name, array $options)
    {
        foreach ($options as $option) {
            if (!isset($config[$option])) {
                throw new \InvalidArgumentException(
                    sprintf('Missing option %s for %s %s', $option, $section, $name)
                );
            }
        }
    }
}
