<?php
/* ============================================================================
(c) Copyright 2012-2014 Hewlett-Packard Development Company, L.P.

     Licensed under the Apache License, Version 2.0 (the "License");
     you may not use this file except in compliance with the License.
     You may obtain a copy of the License at

             http://www.apache.org/licenses/LICENSE-2.0

     Unless required by applicable law or agreed to in writing, software
     distributed under the License is distributed on an "AS IS" BASIS,
     WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
     See the License for the specific language governing permissions and
     limitations under the License.
============================================================================ */
/**
 * This file provides the ObjectStorage class, which is the primary
 * representation of the ObjectStorage system.
 *
 * ObjectStorage (aka Swift) is the OpenStack service for providing
 * storage of complete and discrete pieces of data (e.g. an image file,
 * a text document, a binary).
 */

namespace OpenStack\ObjectStore\v1;

use OpenStack\ObjectStore\v1\Resource\Container;
use OpenStack\ObjectStore\v1\Resource\ACL;
use OpenStack\Common\Transport\GuzzleClient;

/**
 * Access to ObjectStorage (Swift).
 *
 * This is the primary piece of the Object Oriented representation of
 * the Object Storage service. Developers wishing to work at a low level
 * should use this API.
 *
 * There is also a stream wrapper interface that exposes ObjectStorage
 * to PHP's streams system. For common use of an object store, you may
 * prefer to use that system. (@see \OpenStack\Bootstrap).
 *
 * When constructing a new ObjectStorage object, you will need to know
 * what kind of authentication you are going to perform. Older
 * implementations of OpenStack provide a separate authentication
 * mechanism for Swift. You can use ObjectStorage::newFromSwiftAuth() to
 * perform this type of authentication.
 *
 * Newer versions use the IdentityServices authentication mechanism (@see
 * \OpenStack\Identity\v2\IdentityServices). That method is the preferred
 * method.
 *
 * Common Tasks
 *
 * - Create a new container with createContainer().
 * - List containers with containers().
 * - Remove a container with deleteContainer().
 *
 * @todo ObjectStorage is not yet constrained to a particular version
 * of the API. It attempts to use whatever version is passed in to the
 * URL. This is different than IdentityServices, which used a fixed version.
 */
class ObjectStorage
{
    /**
     * The name of this service type in OpenStack.
     *
     * This is used with IdentityService::serviceCatalog().
     */
    const SERVICE_TYPE = 'object-store';

    const API_VERSION = '1';

    const DEFAULT_REGION = 'region-a.geo-1';

    /**
     * The authorization token.
     */
    protected $token = null;
    /**
     * The URL to the Swift endpoint.
     */
    protected $url = null;

    /**
     * The HTTP Client
     */
    protected $client;

    /**
     * Create a new instance after getting an authenitcation token.
     *
     * THIS METHOD IS DEPRECATED. OpenStack now uses Keyston to authenticate.
     * You should use \OpenStack\Identity\v2\IdentityServices to authenticate.
     * Then use this class's constructor to create an object.
     *
     * This uses the legacy Swift authentication facility to authenticate
     * to swift, get a new token, and then create a new ObjectStorage
     * instance with that token.
     *
     * To use the legacy Object Storage authentication mechanism, you will
     * need the follwing pieces of information:
     *
     * - Account ID: This will typically be a combination of your tenantId and
     *   username.
     * - Key: Typically this will be your password.
     * - Endpoint URL: The URL given to you by your service provider.
     *
     * @param string $account Your account name.
     * @param string $key     Your secret key.
     * @param string $url     The URL to the object storage endpoint.
     *
     * @throws \OpenStack\Common\Transport\AuthorizationException if the authentication failed.
     * @throws \OpenStack\Common\Transport\FileNotFoundException  if the URL is wrong.
     * @throws \OpenStack\Common\Exception                        if some other exception occurs.
     *
     * @deprecated Newer versions of OpenStack use Keystone auth instead
     * of Swift auth.
     */
    public static function newFromSwiftAuth($account, $key, $url, \OpenStack\Common\Transport\ClientInterface $client = null)
    {
        $headers = array(
            'X-Auth-User' => $account,
            'X-Auth-Key' => $key,
        );

        // Guzzle is the default client to use.
        if (is_null($client)) {
            $client = new GuzzleClient();
        }

        // This will throw an exception if it cannot connect or
        // authenticate.
        $res = $client->doRequest($url, 'GET', $headers);

        // Headers that come back:
        // X-Storage-Url: https://region-a.geo-1.objects.hpcloudsvc.com:443/v1/AUTH_d8e28d35-3324-44d7-a625-4e6450dc1683
        // X-Storage-Token: AUTH_tkd2ffb4dac4534c43afbe532ca41bcdba
        // X-Auth-Token: AUTH_tkd2ffb4dac4534c43afbe532ca41bcdba
        // X-Trans-Id: tx33f1257e09f64bc58f28e66e0577268a

        $token = $res->getHeader('X-Auth-Token');
        $newUrl = $res->getHeader('X-Storage-Url');

        $store = new ObjectStorage($token, $newUrl, $client);

        return $store;
    }

    /**
     * Given an IdentityServices instance, create an ObjectStorage instance.
     *
     * This constructs a new ObjectStorage from an authenticated instance
     * of an \OpenStack\Identity\v2\IdentityServices object.
     *
     * @param \OpenStack\Identity\v2\IdentityServices $identity An identity services object that already
     *                                                          has a valid token and a service catalog.
     *
     * @return \OpenStack\ObjectStore\v1\ObjectStorage A new ObjectStorage instance.
     */
    public static function newFromIdentity($identity, $region = ObjectStorage::DEFAULT_REGION, \OpenStack\Common\Transport\ClientInterface $client = null)
    {
        $cat = $identity->serviceCatalog();
        $tok = $identity->token();

        return self::newFromServiceCatalog($cat, $tok, $region, $client);
    }

    /**
     * Given a service catalog and an token, create an ObjectStorage instance.
     *
     * The IdentityServices object contains a service catalog listing all of the
     * services to which the present user has access.
     *
     * This builder can scan the catalog and generate a new ObjectStorage
     * instance pointed to the first object storage endpoint in the catalog.
     *
     * @param array  $catalog   The serice catalog from IdentityServices::serviceCatalog().
     *                          This can be either the entire catalog or a catalog
     *                          filtered to just ObjectStorage::SERVICE_TYPE.
     * @param string $authToken The auth token returned by IdentityServices.
     *
     * @return \OpenStack\ObjectStore\v1\ObjectStorage A new ObjectStorage instance.
     */
    public static function newFromServiceCatalog($catalog, $authToken, $region = ObjectStorage::DEFAULT_REGION, \OpenStack\Common\Transport\ClientInterface $client = null)
    {
        $c = count($catalog);
        for ($i = 0; $i < $c; ++$i) {
            if ($catalog[$i]['type'] == self::SERVICE_TYPE) {
                foreach ($catalog[$i]['endpoints'] as $endpoint) {
                    if (isset($endpoint['publicURL']) && $endpoint['region'] == $region) {
                        $os = new ObjectStorage($authToken, $endpoint['publicURL'], $client);

                        return $os;
                    }
                }
            }
        }

        return false;

    }

    /**
     * Construct a new ObjectStorage object.
     *
     * Use this if newFromServiceCatalog() does not meet your needs.
     *
     * @param string $authToken A token that will be included in subsequent
     *                          requests to validate that this client has authenticated
     *                          correctly.
     * @param string $url       The URL to the endpoint. This typically is returned
     *                          after authentication.
     */
    public function __construct($authToken, $url, \OpenStack\Common\Transport\ClientInterface $client = null)
    {
        $this->token = $authToken;
        $this->url = $url;

        // Guzzle is the default client to use.
        if (is_null($client)) {
            $this->client = new GuzzleClient();
        } else {
            $this->client = $client;
        }
    }

    /**
     * Get the authentication token.
     *
     * @return string The authentication token.
     */
    public function token()
    {
        return $this->token;
    }

    /**
     * Get the URL endpoint.
     *
     * @return string The URL that is the endpoint for this service.
     */
    public function url()
    {
        return $this->url;
    }

    /**
     * Fetch a list of containers for this user.
     *
     * By default, this fetches the entire list of containers for the
     * given user. If you have more than 10,000 containers (who
     * wouldn't?), you will need to use $marker for paging.
     *
     * If you want more controlled paging, you can use $limit to indicate
     * the number of containers returned per page, and $marker to indicate
     * the last container retrieved.
     *
     * Containers are ordered. That is, they will always come back in the
     * same order. For that reason, the pager takes $marker (the name of
     * the last container) as a paging parameter, rather than an offset
     * number.
     *
     * @todo For some reason, ACL information does not seem to be returned
     *   in the JSON data. Need to determine how to get that. As a
     *   stop-gap, when a container object returned from here has its ACL
     *   requested, it makes an additional round-trip to the server to
     *   fetch that data.
     *
     * @param int    $limit  The maximum number to return at a time. The default is
     *                       -- brace yourself -- 10,000 (as determined by OpenStack. Implementations
     *                       may vary).
     * @param string $marker The name of the last object seen. Used when paging.
     *
     * @return array An associative array of containers, where the key is the
     *               container's name and the value is an \OpenStack\ObjectStore\v1\ObjectStorage\Container
     *               object. Results are ordered in server order (the order that the remote
     *               host puts them in).
     */
    public function containers($limit = 0, $marker = null)
    {
        $url = $this->url() . '?format=json';

        if ($limit > 0) {
            $url .= sprintf('&limit=%d', $limit);
        }
        if (!empty($marker)) {
            $url .= sprintf('&marker=%d', $marker);
        }

        $containers = $this->get($url);

        $containerList = array();
        foreach ($containers as $container) {
            $cname = $container['name'];
            $containerList[$cname] = Container::newFromJSON($container, $this->token(), $this->url(), $this->client);
        }

        return $containerList;
    }

    /**
     * Get a single specific container.
     *
     * This loads only the named container from the remote server.
     *
     * @param string $name The name of the container to load.
     *
     * @return \OpenStack\ObjectStore\v1\Resource\Container A container.
     *
     * @throws \OpenStack\Common\Transport\FileNotFoundException if the named container is not found on the remote server.
     */
    public function container($name)
    {
        $url = $this->url() . '/' . rawurlencode($name);
        $data = $this->req($url, 'HEAD', false);

        $status = $data->getStatusCode();
        if ($status == 204) {
            $container = Container::newFromResponse($name, $data, $this->token(), $this->url());

            return $container;
        }

        // If we get here, it's not a 404 and it's not a 204.
        throw new \OpenStack\Common\Exception("Unknown status: $status");
    }

    /**
     * Check to see if this container name exists.
     *
     * This method directly checks the remote server. Calling container()
     * or containers() might be more efficient if you plan to work with
     * the resulting container.
     *
     * @param string $name The name of the container to test.
     *
     * @return boolean true if the container exists, false if it does not.
     *
     * @throws \OpenStack\Common\Exception If an unexpected network error occurs.
     */
    public function hasContainer($name)
    {
        try {
            $container = $this->container($name);
        } catch (\OpenStack\Common\Transport\FileNotFoundException $fnfe) {
            return false;
        }

        return true;
    }

    /**
     * Create a container with the given name.
     *
     * This creates a new container on the ObjectStorage
     * server with the name provided in $name.
     *
     * A boolean is returned when the operation did not generate an error
     * condition.
     *
     * - true means that the container was created.
     * - false means that the container was not created because it already
     * exists.
     *
     * Any actual error will cause an exception to be thrown. These will
     * be the HTTP-level exceptions.
     *
     * ACLs
     *
     * Swift supports an ACL stream that allows for specifying (with
     * certain caveats) various levels of read and write access. However,
     * there are two standard settings that cover the vast majority of
     * cases.
     *
     * - Make the resource private: This grants read and write access to
     *   ONLY the creating user tenant. This is the default; it can also be
     *   specified with ACL::makeNonPublic().
     * - Make the resource public: This grants READ permission to any
     *   requesting host, yet only allows the creator to WRITE to the
     *   object. This level can be granted by ACL::makePublic().
     *
     * Note that ACLs operate at a container level. Thus, marking a
     * container public will allow access to ALL objects inside of the
     * container.
     *
     * To find out whether an existing container is public, you can
     * write something like this:
     *
     *     <?php
     *     // Get the container.
     *     $container = $objectStorage->container('my_container');
     *
     *     //Check the permission on the ACL:
     *     $boolean = $container->acl()->isPublic();
     *     ?>
     *
     * For details on ACLs, see \OpenStack\ObjectStore\v1\Resource\ACL.
     *
     * @param string $name     The name of the container.
     * @param object $acl      \OpenStack\ObjectStore\v1\Resource\ACL An access control
     *                         list object. By default, a container is non-public
     *                         (private). To change this behavior, you can add a
     *                         custom ACL. To make the container publically
     *                         readable, you can use this: \OpenStack\ObjectStore\v1\Resource\ACL::makePublic().
     * @param array  $metadata An associative array of metadata to attach to the
     *                         container.
     *
     * @return boolean true if the container was created, false if the container
     *                 was not created because it already exists.
     */
    public function createContainer($name, ACL $acl = null, $metadata = array())
    {
        $url = $this->url() . '/' . rawurlencode($name);
        $headers = array(
            'X-Auth-Token' => $this->token(),
        );

        if (!empty($metadata)) {
            $prefix = Container::CONTAINER_METADATA_HEADER_PREFIX;
            $headers += Container::generateMetadataHeaders($metadata, $prefix);
        }

        // Add ACLs to header.
        if (!empty($acl)) {
            $headers += $acl->headers();
        }

        $data = $this->client->doRequest($url, 'PUT', $headers);
        //syslog(LOG_WARNING, print_r($data, true));

        $status = $data->getStatusCode();

        if ($status == 201) {
            return true;
        } elseif ($status == 202) {
            return false;
        }
        // According to the OpenStack docs, there are no other return codes.
        else {
            throw new \OpenStack\Common\Exception('Server returned unexpected code: ' . $status);
        }
    }

    /**
     * Alias of createContainer().
     *
     * At present, there is no distinction in the Swift REST API between
     * creating an updating a container. In the future this may change, so
     * you are encouraged to use this alias in cases where you clearly intend
     * to update an existing container.
     */
    public function updateContainer($name, ACL $acl = null, $metadata = array())
    {
        return $this->createContainer($name, $acl, $metadata);
    }

    /**
     * Change the container's ACL.
     *
     * This will attempt to change the ACL on a container. If the
     * container does not already exist, it will be created first, and
     * then the ACL will be set. (This is a relic of the OpenStack Swift
     * implementation, which uses the same HTTP verb to create a container
     * and to set the ACL.)
     *
     * @param string $name The name of the container.
     * @param object $acl  \OpenStack\ObjectStore\v1\Resource\ACL An ACL. To make the
     *                     container publically readable, use ACL::makePublic().
     *
     * @return boolean true if the cointainer was created, false otherwise.
     */
    public function changeContainerACL($name, ACL $acl)
    {
        // Oddly, the way to change an ACL is to issue the
        // same request as is used to create a container.
        return $this->createContainer($name, $acl);
    }

    /**
     * Delete an empty container.
     *
     * Given a container name, this attempts to delete the container in
     * the object storage.
     *
     * The container MUST be empty before it can be deleted. If it is not,
     * an \OpenStack\ObjectStore\v1\Resource\ContainerNotEmptyException will
     * be thrown.
     *
     * @param string $name The name of the container.
     *
     * @return boolean true if the container was deleted, false if the container
     *                 was not found (and hence, was not deleted).
     *
     * @throws \OpenStack\ObjectStore\v1\Resource\ContainerNotEmptyException if the container is not empty.
     *
     * @throws \OpenStack\Common\Exception if an unexpected response code is returned. While this should never happen on
     *                              OpenStack servers, forks of OpenStack may choose to extend object storage in a way
     *                              that results in a non-standard code.
     */
    public function deleteContainer($name)
    {
        $url = $this->url() . '/' . rawurlencode($name);

        try {
            $data = $this->req($url, 'DELETE', false);
        } catch (\OpenStack\Common\Transport\FileNotFoundException $e) {
            return false;
        }
        // XXX: I'm not terribly sure about this. Why not just throw the
        // ConflictException?
        catch (\OpenStack\Common\Transport\ConflictException $e) {
            throw new Resource\ContainerNotEmptyException("Non-empty container cannot be deleted.");
        }

        $status = $data->getStatusCode();

        // 204 indicates that the container has been deleted.
        if ($status == 204) {
            return true;
        }
        // OpenStacks documentation doesn't suggest any other return
        // codes.
        else {
            throw new \OpenStack\Common\Exception('Server returned unexpected code: ' . $status);
        }
    }

    /**
     * Retrieve account info.
     *
     * This returns information about:
     *
     * - The total bytes used by this Object Storage instance (`bytes`).
     * - The number of containers (`count`).
     *
     * @return array An associative array of account info. Typical keys are:
     *               - bytes: Bytes consumed by existing content.
     *               - containers: Number of containers.
     *               - objects: Number of objects.
     *
     * @throws \OpenStack\Common\Transport\AuthorizationException if the user credentials are invalid or have expired.
     */
    public function accountInfo()
    {
        $url = $this->url();
        $data = $this->req($url, 'HEAD', false);

        $results = array(
            'bytes' => $data->getHeader('X-Account-Bytes-Used', 0),
            'containers' => $data->getHeader('X-Account-Container-Count', 0),
            'objects' => $data->getHeader('X-Account-Container-Count', 0),
        );

        return $results;
    }

    /**
     * Do a GET on Swift.
     *
     * This is a convenience method that handles the
     * most common case of Swift requests.
     */
    protected function get($url, $jsonDecode = true)
    {
        return $this->req($url, 'GET', $jsonDecode);
    }

    /**
     * Internal request issuing command.
     */
    protected function req($url, $method = 'GET', $jsonDecode = true, $body = '')
    {
        $headers = array(
                'X-Auth-Token' => $this->token(),
        );

        $res = $this->client->doRequest($url, $method, $headers, $body);
        if (!$jsonDecode) {
            return $res;
        }

        return $res->json();

    }
}
