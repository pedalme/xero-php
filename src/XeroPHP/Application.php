<?php

namespace XeroPHP;

use XeroPHP\Remote;
use XeroPHP\Remote\Collection;
use XeroPHP\Remote\OAuth\Client;
use XeroPHP\Remote\Query;
use XeroPHP\Remote\Request;
use XeroPHP\Remote\URL;

abstract class Application
{
    protected static $_config_defaults = [
        'xero'  => [
            'site'            => 'https://api.xero.com',
            'base_url'        => 'https://api.xero.com',
            'core_version'    => '2.0',
            'payroll_version' => '2.0',
            'file_version'    => '1.0',
            'model_namespace' => '\\XeroPHP\\Models'
        ],
        //OAuth config
        'oauth' => [
            'signature_method'   => Client::SIGNATURE_RSA_SHA1,
            'signature_location' => Client::SIGN_LOCATION_HEADER,
            'authorize_url'      => 'https://api.xero.com/oauth/Authorize',
            'request_token_path' => 'oauth/RequestToken',
            'access_token_path'  => 'oauth/AccessToken'
        ],
        'curl'  => [
            CURLOPT_USERAGENT      => 'XeroPHP',
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => 2,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => false,
            CURLOPT_PROXYUSERPWD   => false,
            CURLOPT_ENCODING       => '',
        ]
    ];

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Client
     */
    protected $oauth_client;

    /**
     * @var array
     */
    protected static $_type_config_defaults = [];

    /**
     * @param array $user_config
     */
    public function __construct(array $user_config)
    {
        //better here for overriding
        $this->setConfig($user_config);

        $this->oauth_client = new Client($this->config[ 'oauth' ]);
    }

    /**
     * @return Client
     */
    public function getOAuthClient()
    {
        return $this->oauth_client;
    }

    /**
     * @param string|null $oauth_token
     * @return string
     */
    public function getAuthorizeURL($oauth_token = null)
    {
        return $this->oauth_client->getAuthorizeURL($oauth_token);
    }

    /**
     * @param mixed $key
     * @return mixed
     * @throws Exception
     */
    public function getConfig($key)
    {
        if (!isset($this->config[ $key ])) {
            throw new Exception("Invalid configuration key [$key]");
        }
        return $this->config[ $key ];
    }

    /**
     * @param string $config
     * @param mixed $option
     * @param mixed $value
     * @return mixed
     * @throws Exception
     */
    public function getConfigOption($config, $option)
    {
        if (!isset($this->getConfig($config)[ $option ])) {
            throw new Exception("Invalid configuration option [$option]");
        }
        return $this->getConfig($config)[ $option ];
    }

    /**
     * @param array $config
     * @return array
     */
    public function setConfig($config)
    {
        $this->config = array_replace_recursive(
            self::$_config_defaults,
            static::$_type_config_defaults,
            $config
        );

        return $this->config;
    }

    /**
     * @param string $config
     * @param mixed $option
     * @param mixed $value
     * @return array
     * @throws Exception
     */
    public function setConfigOption($config, $option, $value)
    {
        if (!isset($this->config[ $config ])) {
            throw new Exception("Invalid configuration key [$config]");
        }
        $this->config[ $config ][ $option ] = $value;
        return $this->config;
    }

    /**
     * Validates and expands the provided model class to a full PHP class
     *
     * @param string $class
     * @return string
     * @throws Exception
     */
    public function validateModelClass($class)
    {
        if (class_exists($class)) {
            return $class;
        }

        $class = $this->prependConfigNamespace($class);

        if (!class_exists($class)) {
            throw new Exception("Class does not exist [$class]");
        }

        return $class;
    }


    /**
     * Prepend the configuration namespace to the class.
     *
     * @param  string $class
     * @return string
     */
    protected function prependConfigNamespace($class)
    {
        return $this->getConfig('xero')[ 'model_namespace' ] . '\\' . $class;
    }


    /**
     * As you should never have a GUID for a non-existent object, will throw a NotFoundExceptioon
     *
     * @param $model
     * @param $guid
     * @return Remote\Model|null
     * @throws Exception
     * @throws Remote\Exception\NotFoundException
     */
    public function loadByGUID($model, $guid)
    {
        /** @var Remote\Model $class */
        $class = $this->validateModelClass($model);

        $uri = sprintf('%s/%s', $class::getResourceURI(), $guid);
        $api = $class::getAPIStem();

        $url = new URL($this, $uri, $api);
        $request = new Request($this, $url, Request::METHOD_GET);
        $request->send();

        $elements = $request->getResponse()->getElements();

        if (empty($class::getRootNodeName())) {

            /** @var Remote\Model $object */
            $object = new $class($this);
            $object->fromStringArray($elements);

            return $object;
        }

        foreach ($elements as $element) {

            /** @var Remote\Model $object */
            $object = new $class($this);
            $object->fromStringArray($element);

            return $object;
        }

        return null;
    }

    /**
     * Filter by comma separated string of guid's
     *
     * @param $model
     * @param string $guids
     * @return Collection
     * @throws Exception
     * @throws Remote\Exception\NotFoundException
     */
    public function loadByGUIDs($model, $guids)
    {
        /**
         * @var Remote\Model $class
         */
        $class = $this->validateModelClass($model);

        $uri = sprintf('%s', $class::getResourceURI());
        $api = $class::getAPIStem();

        $url = new URL($this, $uri, $api);
        $request = new Request($this, $url, Request::METHOD_GET);
        $request->setParameter("IDs", $guids);
        $request->send();
        $elements = new Collection();
        foreach ($request->getResponse()->getElements() as $element) {
            /**
             * @var Remote\Model $object
             */
            $object = new $class($this);
            $object->fromStringArray($element);
            $elements->append($object);
        }

        return $elements;
    }

    /**
     * @param string $model
     * @return Query
     * @throws Remote\Exception
     */
    public function load($model)
    {
        $query = new Query($this);
        return $query->from($model);
    }

    /**
     * @param Remote\Model $object
     * @param bool $replace_data
     * @return Remote\Response|null
     * @throws Exception
     */
    public function save(Remote\Model $object, $replace_data = false)
    {
        //Saves any properties that don't want to be included in the normal loop
        //(special saving endpoints)
        $this->savePropertiesDirectly($object);

        if (!$object->isDirty()) {
            return null;
        }
        $object->validate();

        if ($object->hasGUID()) {
            $method = $object::supportsMethod(Request::METHOD_POST) ? Request::METHOD_POST : Request::METHOD_PUT;
            $uri = sprintf('%s/%s', $object::getResourceURI(), $object->getGUID());
        } else {

            //In this case it's new
            $method = $object::getCreateMethod();

            if (is_null($method)) {
                $method =
                    $object::supportsMethod(Request::METHOD_PUT)
                        ? Request::METHOD_PUT
                        : Request::METHOD_POST;
            }

            $uri = $object::getResourceURI();

            //@todo, bump version so you must create objects with app context.
            $object->setApplication($this);
        }

        if (!$object::supportsMethod($method)) {
            throw new Exception(sprintf('%s doesn\'t support [%s] via the API', get_class($object), $method));
        }

        $url = new URL($this, $uri, $object::getAPIStem());

        $request = new Request($this, $url, $method);

        if (!empty($object::getRootNodeName())) {

            //Put in an array with the first level containing only the 'root node'.
            $data = [$object::getRootNodeName() => $object->toStringArray(true)];

            $request->setBody(Helpers::arrayToXML($data))->send();
        } else {
            $data = $object->toStringArray(true);

            $request->setBody(json_encode($data), Request::CONTENT_TYPE_JSON)->send();
        }

        $response = $request->getResponse();
        $current = current($response->getElements());

        if ($current !== false) {
            if (!is_array($current)) {
                $object->fromStringArray($response->getElements(), $replace_data);
            } else {
                $object->fromStringArray($current, $replace_data);
            }
        }

        //Mark the object as clean since no exception was thrown
        $object->setClean();

        return $response;
    }

    /**
     * @param Remote\Model $object
     * @throws Exception
     */
    public function saveRelationships(Remote\Model $object)
    {
        return $this->savePropertiesDirectly($object);
    }

    /**
     * @param Collection|array $objects
     * @return Remote\Response
     * @throws Exception
     */
    public function saveAll($objects, $checkGuid = true)
    {
        $objects = array_values($objects);

        //Just get one type to compare with, doesn't matter which.
        $current_object = $objects[ 0 ];
        /**
         * @var Object $type
         */
        $type = get_class($current_object);
        $has_guid = $checkGuid ? $current_object->hasGUID() : true;
        $object_arrays = [];

        foreach ($objects as $object) {
            if ($type !== get_class($object)) {
                throw new Exception('Array passed to ->saveAll() must be homogeneous.');
            }

            // Check if we have a GUID
            if ($object->hasGUID() && $has_guid === false) {
                $has_guid = true;
            }

            $object_arrays[] = $object->toStringArray(true);
        }

        $request_method = $has_guid ? Request::METHOD_POST : Request::METHOD_PUT;

        $url = new URL($this, $type::getResourceURI(), $type::getAPIStem());
        $request = new Request($this, $url, $request_method);

        //This might need to be parsed and stored some day.
        $root_node_name = Helpers::pluralize($type::getRootNodeName());
        $data = [$root_node_name => $object_arrays];

        $request->setBody(Helpers::arrayToXML($data));
        $request->setParameter('SummarizeErrors', 'false');
        $request->send();

        $response = $request->getResponse();

        foreach ($response->getElements() as $element_index => $element) {
            if ($response->getErrorsForElement($element_index) === null) {
                $objects[ $element_index ]->fromStringArray($element);
                $objects[ $element_index ]->setClean();
            }
        }

        return $response;
    }

    /**
     * Function to save properties directly which do not update via a POST.
     *
     * This is called automatically from the save method for things like
     * adding contacts to ContactGroups
     *
     * @param Remote\Model $object
     * @throws Exception
     */
    private function savePropertiesDirectly(Remote\Model $object)
    {
        foreach ($object::getProperties() as $property_name => $meta) {
            if ($meta[ Remote\Model::KEY_SAVE_DIRECTLY ] && $object->isDirty($property_name)) {
                $property_objects = $object->$property_name;

                if ($property_objects instanceof Remote\Model) {

                    /** @var Remote\Model $model */
                    $model = $property_objects;

                    $url = new URL(
                        $this,
                        sprintf(
                            '%s/%s/%s',
                            $object::getResourceURI(),
                            $object->getGUID(),
                            $model::getResourceURI()
                        ),
                        $model::getAPIStem()
                    );

                    $method = $model::getCreateMethod();
                    $data = $model->toStringArray(true);

                    $request = new Request($this, $url, $method);
                    $request->setBody(json_encode($data), Request::CONTENT_TYPE_JSON);
                    $request->send();

                    $response = $request->getResponse();

                    foreach ($response->getElements() as $key => $element) {
                        if ($response->getErrorsForElement($key) === null) {
                            $method = 'set' . ucfirst($key);

                            if (method_exists($model, $method)) {
                                $model->{$method}($element);
                                $model->setClean($key);
                            }
                        }
                    }

                    continue;
                }

                /** @var Remote\Model[] $property_type */
                $property_type = get_class(current($property_objects));

                $url = new URL(
                    $this,
                    sprintf(
                        '%s/%s/%s',
                        $object::getResourceURI(),
                        $object->getGUID(),
                        $property_type::getResourceURI()
                    ),
                    $object::getAPIStem()
                );

                $method = $property_type::getCreateMethod();
                $request = new Request($this, $url, $method);

                $property_array = [];

                /** @var Object[] $property_objects */
                foreach ($property_objects as $property_object) {
                    $property_array[] = $property_object->toStringArray(false);
                }


                if (!empty($property_type::getRootNodeName())) {
                    $root_node_name = Helpers::pluralize($property_type::getRootNodeName());

                    $request->setBody(Helpers::arrayToXML([$root_node_name => $property_array]));
                } else {
                    if (count($property_array) > 1) {
                        foreach ($property_array as $relation) {
                            $request = new Request($this, $url, $method);
                            $request->setBody(json_encode($relation), Request::CONTENT_TYPE_JSON);
                            $request->send();

                            $response = $request->getResponse();

                            foreach ($response->getElements() as $element_index => $element) {
                                if ($response->getErrorsForElement($element_index) === null && isset($property_objects[ $element_index ])) {
                                    $property_objects[ $element_index ]->fromStringArray($element);
                                    $property_objects[ $element_index ]->setClean();
                                }
                            }
                        }

                        $object->setClean($property_name);

                        return;
                    }

                    $request->setBody(json_encode($property_array[ 0 ]), Request::CONTENT_TYPE_JSON);
                }

                $request->send();

                $response = $request->getResponse();

                foreach ($response->getElements() as $element_index => $element) {
                    if ($response->getErrorsForElement($element_index) === null && isset($property_objects[ $element_index ])) {
                        $property_objects[ $element_index ]->fromStringArray($element);
                        $property_objects[ $element_index ]->setClean();
                    }
                }

                //Set it clean so the following save might have nothing to do.
                $object->setClean($property_name);
            }
        }
    }

    /**
     * @param Remote\Model $object
     * @return Remote\Response
     * @throws Exception
     */
    public function delete(Remote\Model $object)
    {
        if (!$object::supportsMethod(Request::METHOD_DELETE)) {
            throw new Exception(
                sprintf(
                    '%s doesn\'t support [DELETE] via the API',
                    get_class($object)
                )
            );
        }

        $uri = sprintf('%s/%s', $object::getResourceURI(), $object->getGUID());
        $api = $object::getAPIStem();
        $url = new URL($this, $uri, $api);
        $request = new Request($this, $url, Request::METHOD_DELETE);
        $response = $request->send();

        if (false !== $element = current($response->getElements())) {
            $object->fromStringArray($element, true);
        }

        return $object;
    }
}
