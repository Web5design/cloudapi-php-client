<?php
// $Id$

namespace Acquia\CloudApi\Client;

/**
 * @file
 * cloudapi REST calls
 */

class CloudApiException extends Exception { }
class CloudResourceNotFoundException extends CloudApiException { }
class CloudApiCallNotImplemented extends Exception { }

/**
 * The CloudApi object is responsible for performing the cloudapi calls.
 *
 * This object provides sensible method signatures for almost all of
 * the cloudapi calls.  The only one that is missing at this point is
 * the one that installs a drupal distro.  I can't see that being
 * relevant to our work, and actually could be quite dangerous.
 *
 * Configuration:
 * This class is meant to be used from the gardener.  The other sites
 * in gardens will not have the appropriate credentials.
 *
 * Example:
 * $api = New CloudApi()
 * print_r($api->listDatabases('tangle001', 'prod'));
 */
class CloudApi {
  private static $instances = array();
  private $DEFAULT_CREDS_FILENAME = '';
  static protected $allCreds = array();
  private $creds = NULL;
  protected $cainfo = NULL;
  protected $default_options = array();

  /**
   * Constructor.
   *
   * @param $site_name
   *   The name of the site for which credentials should be used.
   * @param $stage
   *   Optional.  If provided a file with the suffix matching the
   *   stage will be used rather than the default cloudapi.ini file.
   * @param $creds_filenmae
   *   Optional.  If provided the specified credential file will be
   *   used instead of the one in the normal location.  Used for
   *   testing only.
   */
  private function __construct($site_name, $stage = NULL, $creds_filename = NULL) {
    if (empty($creds_filename)) {
      $creds_filename = sprintf($this->DEFAULT_CREDS_FILENAME . '.ini', $site_name);
      if (!empty($stage)) {
        // Allow a directory of credential files - each with the stage as
        // the extension.
        $extension = (empty($stage) ? 'ini' : $stage);
        $test_file = sprintf($this->DEFAULT_CREDS_FILENAME . '.%s', $site_name, $extension);
        if (file_exists($test_file)) {
          $creds_filename = $test_file;
        }
      }
    }

    if (empty(CloudApi::$allCreds[$creds_filename])) {
      // Read the .ini file.
      if (file_exists($creds_filename) && is_readable($creds_filename)) {
        CloudApi::$allCreds[$creds_filename] = parse_ini_file($creds_filename, true);
      }
    }
    if (empty(CloudApi::$allCreds[$creds_filename])) {
      throw new CloudApiException(sprintf('CREDENTIALS: Cloud credentials not available (check %s)', $creds_filename));
    }
    $this->creds = CloudApi::$allCreds[$creds_filename];
  }

  /**
   * Returns an instance of CloudApi, reusing a previous one if available.
   *
   * @param $site_name
   *   The name of the site for which credentials should be used.
   * @param $stage
   *   Optional.  If provided a file with the suffix matching the
   *   stage will be used rather than the default cloudapi.ini file.
   * @param $creds_filenmae
   *   Optional.  If provided the specified credential file will be
   *   used instead of the one in the normal location.  Used for
   *   testing only.
   *
   * @return CloudApi
   *   An instance of the CloudApi class.
   */
  public static function getInstance($site_name = NULL, $stage = NULL, $creds_filename = NULL) {
    // Not thread-safe, but doesn't need to be.
    if (!isset(self::$instances[$site_name][$stage][$creds_filename])) {
      self::$instances[$site_name][$stage][$creds_filename] = new CloudApi($site_name, $stage, $creds_filename);
    }
    return self::$instances[$site_name][$stage][$creds_filename];
  }

  /**
   * Set the path to a CA file for curl.
   *
   * This is mostly useful when using a crufty version of PHP for development
   * and the provided CA file is outdated. You can get an updated one from
   * http://curl.haxx.se/docs/caextract.html
   *
   * @param $path
   *   The path to a CA PEM file.
   *
   * @return
   *   The instance
   */
  public function setCaInfo($path) {
    if (file_exists($path)) {
      $this->cainfo = $path;
    }
    else {
      throw new CloudApiException("{$path} does not exist.");
    }
    return $this;
  }

  /**
   * Set a default option for the callAcapi() method.
   *
   * @param $key
   *   String key.
   * @param $value
   *   mixed value.
   *
   * @return
   *   The instance
   */
  public function setDefaultCallOption($key, $value) {
    $this->default_options[$key] = $value;
    return $this;
  }

  /**
   * Returns the set of all site names from the credentials file.
   *
   * @return
   *   An array in which each element represents a site name.
   */
  public function getAllSiteNames() {
    $sites = array();
    foreach ($this->creds as $key => $value) {
      if (is_array($value) && !empty($value['username']) && !empty($value['password'])) {
        $sites[] = $key;
      }
    }
    return $sites;
  }

  /**
   * Delete a database
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $db
   *   the database name
   * @param $backup
   *   a final backup of the database instance in each environment is made before deletion unless this query parameter has value 0.
   *
   * @return
   *   the task record for deleting the database
   */
  public function deleteDatabase($site, $db, $backup = 1) {
    $site_name = $this->getSiteName($site);
    $method = 'DELETE';
    $resource = sprintf('/sites/%s/dbs/%s', $this->getSiteName($site), $db);
    $params = array('backup' => $backup);
    $result = $this->callAcapi($site, $method, $resource, $params);
    return $result['result'];
  }

  /**
   * Delete a site environment database instance backup.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $db
   *   the database name
   * @param $backup
   *   the backup id
   *
   * @return
   *   the task record for deleting the backup
   */
  public function deleteDatabaseBackup($site, $env, $db, $backup) {
    $site_name = $this->getSiteName($site);
    $method = 'DELETE';
    $resource = sprintf('/sites/%s/envs/%s/dbs/%s/backups/%s', $this->getSiteName($site), $env, $db, $backup);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Delete a domain.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $domain
   *   the domain name to delete
   *
   * @return
   *   the task record for deleting the domain
   */
  public function deleteDomain($site, $env, $domain) {
    $site_name = $this->getSiteName($site);
    $method = 'DELETE';
    $resource = sprintf('/sites/%s/envs/%s/domains/%s', $this->getSiteName($site), $env, $domain);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Purge the Varnish cache for a domain.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $domain
   *   the domain name to delete
   *
   * @return
   *   the task record for purging the cache
   */
  public function purgeVarnish($site, $env, $domain) {
    $site_name = $this->getSiteName($site);
    $method = 'DELETE';
    $resource = sprintf('/sites/%s/envs/%s/domains/%s/cache', $this->getSiteName($site), $env, $domain);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Delete an SSH key.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $sshkeyid
   *   Key id
   *
   * @return
   *   the task record for deleting the key
   */
  public function deleteSSHKey($site, $sshkeyid) {
    $site_name = $this->getSiteName($site);
    $method = 'DELETE';
    $resource = sprintf('/sites/%s/sshkeys/%s', $this->getSiteName($site), $sshkeyid);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Delete an SVN user
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $svnuserid
   *   SVN user id
   *
   * @return
   *   the task record for deleting the user
   */
  public function deleteSVNUser($site, $sshuserid) {
    $site_name = $this->getSiteName($site);
    $method = 'DELETE';
    $resource = sprintf('/sites/%s/svnusers/%s', $this->getSiteName($site), $sshkeyid);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Lists all sites accessible by the caller.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   *
   * @return
   *   array of sites you can access
   */
  public function listSites($site) {
    $method = 'GET';
    $resource = '/sites';
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Gets a site record.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   *
   * @return
   *   object with information about the selected site
   */
  public function getSiteRecord($site) {
    $site_name = $this->getSiteName($site);
    $method = 'GET';
    $resource = sprintf('/sites/%s', $this->getSiteName($site));
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * List a site's environments.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   *
   * @return
   *   array of the site's environments
   */
  public function listEnvironments($site) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs', $this->getSiteName($site));
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Gets an environment record.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   *
   * @return
   *   object with information about the selected environment
   */
  public function getEnvironmentInfo($site, $env) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s', $this->getSiteName($site), $env);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * List a site environment's database instances.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   *
   * @return
   *   an array of the databases within a site's environment
   */
  public function listDatabases($site, $env) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/dbs', $this->getSiteName($site), $env);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get a database instance.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $db
   *   the database name (ex: 'g76')
   *
   * @return
   *   an object containing information about the database
   */
  public function getDatabaseInfo($site, $env, $db) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/dbs/%s', $this->getSiteName($site), $env, $db);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * List a site environment's database instance backups.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $db
   *   the database name (ex: 'g76')
   *
   * @return
   *   an array of database backup records
   */
  public function listDatabaseBackups($site, $env, $db) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/dbs/%s/backups', $this->getSiteName($site), $env, $db);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get details about a database instance backup.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $db
   *   the database name (ex: 'g76')
   * @param $backup
   *   the backup id (ex: 217005)
   * @return
   *   An object with details about the database backup
   */
  public function getDatabaseBackupInfo($site, $env, $db, $backup) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/dbs/%s/backups/%s', $this->getSiteName($site), $env, $db, $backup);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get the location from which a backup can be downloaded.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $db
   *   the database name (ex: 'g76')
   * @param $backup
   *   the backup id (ex: 217005)
   * @return
   *   A string containing the backup location.
   */
  public function getDatabaseBackupLocation($site, $env, $db, $backup) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/dbs/%s/backups/%s/download', $this->getSiteName($site), $env, $db, $backup);
    $options = array('include_header' => TRUE);
    $result = $this->callAcapi($site, $method, $resource, array(), array(), $options);
    // Now parse the HTTP header, find the location.
    $matches = array();
    if (1 == preg_match('/^Location: (.*)$/m', $result['content'], $matches)) {
      $result['result'] = $matches[1];
    }
    else {
      throw new CloudApiException(sprintf('BACKUP_NOT_FOUND: Could not find backup %s', $backup));
    }
    return $result['result'];
  }

  /**
   * List the environment's domains.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @return
   *   An array of the environment's domain names.
   */
  public function listDomains($site, $env) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/domains', $this->getSiteName($site), $env);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get a domain record.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $domain
   *   the domain name (ex: bar.com)
   * @return
   *   A domain record.
   */
  public function getDomainInfo($site, $env, $domain) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/domains/%s', $this->getSiteName($site), $env, $domain);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * List a site environment's servers.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @return
   *   An array of objects with information about the servers
   */
  public function listServers($site, $env) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/servers', $this->getSiteName($site), $env);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get a server record.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $server
   *   the server name
   * @return
   *   An array of objects with information about the selected server
   */
  public function getServerInfo($site, $env, $server) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/envs/%s/servers/%s', $this->getSiteName($site), $env, $server);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * List a site's SSH keys.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @return
   *   An array of the site's SSH keys.
   */
  public function listSSHKeys($site) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/sshkeys', $this->getSiteName($site));
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get an SSH key
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $sshkeyid
   *   SSH key id
   * @return
   *   SSH key record
   */
  public function getSSHKey($site, $sshkeyid) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/sshkeys/%s', $this->getSiteName($site), $sshkeyid);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * List a site's SVN users.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @return
   *   An array of SVN user records
   */
  public function listSVNUsers($site) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/svnusers', $this->getSiteName($site));
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get an SVN user.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $svnuserid
   *   SVN user id
   * @return
   *   SVN user record
   */
  public function getSVNUser($site, $svnuserid) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/svnusers/%s', $this->getSiteName($site), $svnuserid);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * List a site's tasks.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @return
   *   an array of objects that represent the tasks
   */
  public function getTasks($site) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/tasks', $this->getSiteName($site));
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Get a task record.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $task
   *   the task id
   * @return
   *   an array of objects that represent the tasks
   */
  public function getTaskInfo($site, $task) {
    $method = 'GET';
    $resource = sprintf('/sites/%s/tasks/%s', $this->getSiteName($site), $task);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Deploy code from one site environment to another.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $source
   *   the source environment name
   * @param $target
   *   the target environment name
   * @return
   *   the task record for deploying the code
   */
  public function deployCode($site, $source, $target) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/code-deploy/%s/%s', $this->getSiteName($site), $source, $target);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Add a database
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $db
   *   the database name
   * @param $cluster_map
   *   a mapping containing all environments and the cluster to which
   *   the associated database should be created.  (See
   *   getDatabaseClusterMap).  Note that if more than one cluster is
   *   associated with a sitegroup, this map is required.
   * @return
   *   the task record for adding the database
   */
  public function addDatabase($site, $db, $cluster_map = NULL) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/dbs', $this->getSiteName($site));
    $body = array('db' => $db);
    if (!empty($cluster_map)) {
      $body['options'] = array();
      foreach ($cluster_map as $env => $db_cluster) {
        $body['options'][$env] = array('db_cluster' => (string)$db_cluster);
      }
    }

    $result = $this->callAcapi($site, $method, $resource, array(), $body);
    return $result['result'];
  }

  /**
   * Returns an array of suitable database clusters for each environment.
   *
   * This array structure is meant to be passed into the addDatabase
   * method and provides a suitable database cluster for each
   * environment of the specified site.  When adding a database, a new
   * database will be added to each environment, and if an attempt is
   * made to add a database to an environment on a cluster that is not
   * associated with that environment the addDatabase call will create
   * a task that will fail.
   *
   * Getting the array from this method guarantees that each
   * environment will have a suitable cluster_id.  Any desired
   * modifications can be made and then the database add should work
   * correctly.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $envs
   *   The environment list (from listEnvironments).  If not provided
   *   the environments will be retrieved.
   * @return
   *   An array in which all environment names are represented as keys
   *   and appropriate database cluster ids are represented as the
   *   values.
   */
  public function getDatabaseClusterMap($site, $envs = NULL) {
    $result = array();
    if (empty($envs)) {
      $envs = $this->listEnvironments($site);
    }
    foreach ($envs as $env) {
      if (!empty($env->db_clusters)) {
        for ($i = 0; $i < count($env->db_clusters); $i++) {
          $cluster = $env->db_clusters[$i];
          if ($cluster > 0) {
            $result[$env->name] = $cluster;
            break;
          }
        }
      }
    }
    return $result;
  }

  /**
   * Copy a database from one site environment to another
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $db
   *   the database name
   * @param $source
   *   the source environment name
   * @param $target
   *   the target environment name
   * @return
   *   the task record for deploying the code
   */
  public function copyDatabase($site, $db, $source, $target) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/dbs/%s/db-copy/%s/%s', $this->getSiteName($site), $db, $source, $target);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Deploy a specific VCS branch or tag to an environment.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name
   * @param $path
   *   the name of the branch or tag (e.g. master or tags/tagname)
   * @return
   *   the task record for deploying the code
   */
  public function deployCodePath($site, $env, $path) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/envs/%s/code-deploy', $this->getSiteName($site), $env);
    $params = array('path' => $path);
    $result = $this->callAcapi($site, $method, $resource, $params);
    return $result['result'];
  }

  /**
   * Create a database instance backup.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name
   * @param $db
   *   the database name
   * @return
   *   the task record for creating the backup
   */
  public function backupDatabase($site, $env, $db) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/envs/%s/dbs/%s/backups', $this->getSiteName($site), $env, $db);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Restore a site environment database instance backup.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name
   * @param $db
   *   the database name
   * @param $backup
   *   the backup id
   * @return
   *   the task record for creating the backup
   */
  public function restoreDatabase($site, $env, $db, $backup) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/envs/%s/dbs/%s/backups/%s/restore', $this->getSiteName($site), $env, $db, $backup);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Add a domain name.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name
   * @param $domain
   *   the domain name to add
   * @return
   *   the task record for adding the domain name
   */
  public function addDomain($site, $env, $domain) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/envs/%s/domains/%s', $this->getSiteName($site), $env, $domain);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Moves domains atomically from one environment to another.
   *
   * @param string|array $domains
   *   The domain name(s) as an array of strings, or the string '*' to move all
   *   domains.
   * @param string $sitegroup
   *   The hosting sitegroup name.
   * @param string $from_env
   *   The environment which currently has this domain.
   * @param string $to_env
   *   The destination environment for the domain.
   * @param boolean $skip_site_update
   *   If set to TRUE (default), this will inhibit running fields-config-web.php
   *   for this domain move.
   *
   * @return
   *   The task record for moving the domain.
   */
  public function moveDomains($domains, $sitegroup, $from_env, $to_env, $skip_site_update = TRUE) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/domain-move/%s/%s', $this->getSiteName($sitegroup), $from_env, $to_env);
    $body = array('domains' => $domains);
    $params = $skip_site_update ? array('skip_site_update' => TRUE) : array();
    $result = $this->callAcapi($sitegroup, $method, $resource, $params, $body);
    return $result['result'];
  }

  /**
   * Moves all domains on a site atomically from one environment to another.
   *
   * This is just a special case of moveDomain, using the wildcard as domain.
   *
   * @param string $sitegroup
   *   The hosting sitegroup name.
   * @param string $from_env
   *   The hosting environment to take domains from.
   * @param string $to_env
   *   The hosting environment to move domains to.
   * @param boolean $skip_site_update
   *   If set to TRUE (default), this will inhibit running fields-config-web.php
   *   for this domain move.
   *
   * @return array
   *   The task record for moving the domains.
   */
  public function moveAllDomains($sitegroup, $from_env, $to_env, $skip_site_update = TRUE) {
    return $this->moveDomains('*', $sitegroup, $from_env, $to_env, $skip_site_update);
  }

  /**
   * Installs a drupal distro.
   *
   * Seriously, don't call this.  It will throw an exception.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $env
   *   the environment name (ex: prod, dev)
   * @param $type
   *   the type of distro source.  ex: 'distro_name', 'distro_url', 'make_url'
   * @param $source
   *   a distro name, URL to a distro, or URL to a Drush make file.
   */
  public function installEnvironment($site, $env, $type, $source) {
    throw new CloudApiCallNotImplemented('CALL: Cloud call "installEnvironment" is not implemented for your own safety.');
    $method = 'POST';
    $resource = sprintf('/sites/%s/envs/%s/install/%s', $this->getSiteName($site), $env, $type);
    $params = array('source' => $source);
    $result = $this->callAcapi($site, $method, $resource, $params);
    return $result['result'];
  }

  /**
   * Copy files from one site environment to another.
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $source
   *   the source environment name
   * @param $target
   *   the target environment name
   * @return
   *   the task record for copying the files
   */
  public function copyFiles($site, $source, $target) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/files-copy/%s/%s', $this->getSiteName($site), $source, $target);
    $result = $this->callAcapi($site, $method, $resource);
    return $result['result'];
  }

  /**
   * Add an SSH key
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $nickname
   *   key nickname
   * @param $ssh_pub_key
   *   SSH public key
   * @return
   *   the task record for adding the key
   */
  public function addSSHKey($site, $nickname, $ssh_pub_key) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/sshkeys', $this->getSiteName($site));
    $params = array('nickname' => $nickname);
    $body = array('ssh_pub_key' => $ssh_pub_key);
    $result = $this->callAcapi($site, $method, $resource, $params, $body);
    return $result['result'];
  }

  /**
   * Add an SVN user
   *
   * @param $site
   *   the site name (ex: tangle001, gardener, etc.)
   * @param $useranme
   *   svn username
   * @param $password
   *   user svn password
   * @return
   *   the task record for adding the user
   */
  public function addSVNUser($site, $username, $password) {
    $method = 'POST';
    $resource = sprintf('/sites/%s/svnusers/%s', $this->getSiteName($site), $username);
    $params = array();
    $body = array('password' => $password);
    $result = $this->callAcapi($site, $method, $resource, $params, $body);
    return $result['result'];
  }

  /**
   * Call an Acquia Cloud API resource.
   *
   * @param $site
   *   The site name (ex: tangle001)
   * @param $method
   *   The HTTP method; e.g. GET.
   * @param $resource
   *   The API function to call; e.g. /sites/:site.
   * @param $args = array()
   *   An array of argument values for the resource; e.g: array(':site' =>
   *   'mysite').
   * @params $params = array()
   *   An array of query parameters to append to the URL.
   * @params $body = array()
   *   An array of parameters to include in the POST body in JSON format.
   * @params $options = array()
   *   An array of options:
   *   - display (TRUE): whether to output the result to stdout
   *   - result_stream: open stream to which to write the response body
   *   - redirect: the maximum number of redirects to allow
   *   - no_verify_peer: skip SSL peer verifcation (for development).
   */
  private function callAcapi($site, $method, $resource, $params = array(), $body = array(), $options = array()) {
    // Merge in default options.
    $options = $options + $this->default_options;
    // Build the API call URL.
    $endpoint = $this->creds['endpoint'];
    $url = sprintf('%s%s.json', $endpoint, $resource);
    // TODO: Add the caller parameter.
    //    $params['caller'] = acapi_get_option('caller');
    foreach ($params as $k => $v) {
      $params[$k] = "$k=" . urlencode($v);
    }
    $url .= '?' . implode('&', $params);

    $creds = $this->getCreds($site);

    // Build the body.
    $json_body = json_encode($body);
    $headers = array();
    $ch = curl_init($url);
    // Basic request settings
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_USERAGENT, basename(__FILE__));
    if (!empty($options['result_stream'])) {
      curl_setopt($ch, CURLOPT_FILE, $options['result_stream']);
    }
    else {
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    }
    // User authentication
    curl_setopt($ch, CURLOPT_HTTPAUTH, TRUE);
    curl_setopt($ch, CURLOPT_USERPWD, $creds);
    // SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $verify_peer = empty($options['no_verify_peer']) && preg_match('@^https:@', $endpoint);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_peer);
    if ($this->cainfo) {
      curl_setopt($ch, CURLOPT_CAINFO, $this->cainfo);
    }

    // Redirects
    if (!empty($options['redirect'])) {
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
      curl_setopt($ch, CURLOPT_MAXREDIRS, $options['redirect']+1);
    }
    // Body
    if (!empty($body)) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
      $headers[] = 'Content-Type: application/json;charset=utf-8';
      $headers[] = 'Content-Length: ' . strlen($json_body);
    }
    // Headers
    if (!empty($headers)) {
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if (!empty($options['include_header'])) {
      // Include the header in the output.
      curl_setopt($ch, CURLOPT_HEADER, TRUE);
    }
    // Go
    $content = curl_exec($ch);
    if (curl_errno($ch) > 0) {
      throw new CloudApiException(sprintf('ACAPI_CURL_ERROR: Error accessing API: %s (args: %s)', curl_error($ch), print_r(func_get_args, TRUE)));
    }

    $result = json_decode($content);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200 && $status != 307) {
      switch ($status) {
        case 404:
          throw new CloudResourceNotFoundException(sprintf('ACAPI_RETURN_CODE: Resource not found. (args: %s)', print_r(func_get_args(), TRUE)), $status);
          break;

        default:
          throw new CloudApiException(sprintf('ACAPI_RETURN_CODE: Error returned from Cloud API: %s (result: %s) (args: %s)', $status, print_r($result, TRUE), print_r(func_get_args(), TRUE)), $status);
      }
    }
    if (!empty($options['include_header'])) {
      return array('result' => $result, 'content' => $content);
    }
    return array('result' => $result);
  }

  /**
   * Returns the stage this CloudApi instance is configured to communicate with.
   */
  public function getStage() {
    return $this->creds['stage'];
  }

  /**
   * Returns the site name.
   *
   * This method allows us to do aliases in the credential files.  For
   * example we could have an entry:
   *     gardener=gardener-utest
   * which would allow us to refer to the site as gardener in our
   * code, and would be resolved to the correct name via the
   * credentials file.
   *
   * @param {String} $site
   *   The name of the site
   */
  public function getSiteName($site) {
    if (empty($site) || empty($this->creds[$site])) {
      throw new CloudApiException(sprintf('SITE NAME: Site name [%s] is not a recognized site or site alias.', $site));
    }
    return is_array($this->creds[$site]) ? $site : $this->creds[$site];
  }

  /**
   * Returns the credentials for the specified site.
   *
   * @param {mixed} $site
   *   The site name
   * @return
   *   The credential string used by the Cloud API.
   */
  private function getCreds($site) {
    $site_name = $this->getSiteName($site);
    $creds = $this->creds[$site_name];
    return sprintf('%s:%s', $creds['username'], $creds['password']);
  }

  /**
   * This class is not serializable.
   *
   * Because CloudApi credentials are cached as part of this object,
   * serializing it will provide those credentials in clear text.
   */
  public function __sleep() {
    throw new CloudApiException('Instances of the CloudApi class are not serializable.');
  }
}
