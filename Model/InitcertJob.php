<?php

App::uses("CoJobBackend", "Model");
App::uses("CoPerson", "Model");
App::uses("Certificate", "CertificateAuthenticator");
App::uses("CoJobHistoryRecord", "Model");

class InitcertJob extends CoJobBackend {
  // Required by COmanage Plugins
  public $cmPluginType = "job";

  // Document foreign keys
  public $cmPluginHasMany = array();

  // Validation rules for table elements
  public $validate = array();

  /**
   * Expose menu items.
   * 
   * @return Array with menu location type as key and array of labels, controllers, actions as values.
   */
  public function cmPluginMenus() {
    return array();
  }


  /**
   * Execute the requested Job.
   *
   * @param  int   $coId    CO ID
   * @param  CoJob $CoJob   CO Job Object, id available at $CoJob->id
   * @param  array $params  Array of parameters, as requested via parameterFormat()
   * @throws InvalidArgumentException
   * @throws RuntimeException
   */
  public function execute($coId, $CoJob, $params) {
    // The id for the CertificateAuthenticator used for CILogon certificates.
    $certAuthId = $params['certauthid'];

    // The description to set for the certificate.
    $certDescription = 'CILogon Issued Certificate';

    // Mark job as being processed.
    $CoJob->update($CoJob->id, null, "full", null);

    $CoPerson = new CoPerson();

    // We need to dynamically bind the CertificateAuthenticator.Certificate
    // and CoJobHistoryRecord models since the CoPerson class does not 
    // explicitly declare the relation since the plugin  is an available plugin
    // not enabled by default.
    $CoPerson->bindModel(array('hasMany' => array('CertificateAuthenticator.Certificate')), false);
    $CoPerson->bindModel(array('hasMany' => array('CoJobHistoryRecord')), false);

    // Find all CO Person records with Active status and not deleted.
    // Include role, cou, org identity link, org identity, identifier
    // and certificate information.
    $args = array();
    $args['conditions']['CoPerson.co_id'] = $coId;
    $args['conditions']['CoPerson.status'] = StatusEnum::Active;
    $args['contain']['CoPersonRole'] = 'Cou';
    $args['contain']['CoOrgIdentityLink']['OrgIdentity'] = 'Identifier';
    $args['contain']['Certificate'] = array();

    try {
      $coPeople = $CoPerson->find('all', $args);
    } catch (Throwable $t) {
      $this->log("Caught error/exception: " . $t->getMessage() . "\n");
      $this->log($t->getTraceAsString() . "\n");
      $CoJob->finish($CoJob->id, "", JobStatusEnum::Complete);
      return;
    }

    // Loop over the CO Person records to find active roles in the LDG Grid Account Holders
    // COU and save the CO Person ID in an array.
    $ldgAccountHolders = array();

    foreach($coPeople as $coPerson) {
      if(!empty($coPerson['CoPersonRole'])) {
        foreach($coPerson['CoPersonRole'] as $role) {
          if(!empty($role['Cou']['name'])) {
            if($role['Cou']['name'] == 'LDG Grid Account Holders'
               && $role['status'] == StatusEnum::Active
               && $role['deleted'] == false) {
               $ldgAccountHolders[] = $coPerson['CoPerson']['id'];
            }
          }
        }
      }
    }

    // Loop over the CO Person records and for those with active roles in the
    // LDG Grid Account Holders find the identifier of type sorid or eppn with
    // a value issued by CILogon and write it to a file.
    $cilogonUidFile = fopen("/srv/comanage-registry/local/cilogon_uids", "w");

    foreach($coPeople as $coPerson) {
      if(in_array($coPerson['CoPerson']['id'], $ldgAccountHolders)) {
        $coPersonId = $coPerson['CoPerson']['id'];
        if(!empty($coPerson['CoOrgIdentityLink'])) {
          foreach($coPerson['CoOrgIdentityLink'] as $link) {
            if(!empty($link['OrgIdentity'])) {
              if(!empty($link['OrgIdentity']['Identifier'])) {
                foreach($link['OrgIdentity']['Identifier'] as $identifierObj) {
                  if(($identifierObj['type'] == 'sorid' ||
                      $identifierObj['type'] == 'eppn') &&
                      preg_match('@^http://cilogon.org.+@', $identifierObj['identifier']) ) {
                      fwrite($cilogonUidFile, $coPersonId . "|" . $identifierObj['identifier'] . "\n");
                  }
                }
              }
            }
          }
        }
      }
    }

    fclose($cilogonUidFile);

    // Exit now if no mapping file from CO Person ID and 
    // CILogon UID to CILogon certificate subject can be found.
    $mappingFilePath = "/srv/comanage-registry/local/cilogon_uids_dns";
    if(!is_readable($mappingFilePath)) {
      $this->log("Could not find file $mappingFilePath so exiting");
      $CoJob->finish($CoJob->id, "", JobStatusEnum::Complete);
      return;
    }

    // Read mappings from CO Person ID and CILogon UID to
    // CILogon certificate subject.
    $coPersonDnMap = array();

    $mappingFile = fopen($mappingFilePath, "r");

    while(!feof($mappingFile)) {
      $line = trim(fgets($mappingFile));
      if($line) {
        $fields = explode("|", $line);
        $coPersonDnMap[$fields[0]] = $fields[2];
      }
    }

    fclose($mappingFile);

    // Loop over CO Person objects and add if necessary a certificate.
    foreach($coPeople as $coPerson) {
      $coPersonId = $coPerson['CoPerson']['id'];
      if(array_key_exists($coPersonId, $coPersonDnMap)) {
        // Assume we need to add a certificate unless we find otherwise.
        $add = true;
        
        // Loop over any existing certificates to see if they are
        // CILogon issued.
        foreach($coPerson['Certificate'] as $cert) {
          if($cert['description'] == $certDescription &&
             $cert['certificate_authenticator_id'] == $certAuthId) {
             $add = false;
             break;
          }
        }

        if(!$add) {
          continue;
        }

        $dnGridFormat = $coPersonDnMap[$coPersonId];
        $dn = substr(implode(',', array_reverse(explode('/', $dnGridFormat))), 0, -1);

        $CoPerson->Certificate->clear();
        $CoPerson->CoJobHistoryRecord->clear();
        $CoPerson->HistoryRecord->clear();

        $newCert = array();
        $newCert['Certificate']['certificate_authenticator_id'] = $certAuthId;
        $newCert['Certificate']['co_person_id'] = $coPersonId;
        $newCert['Certificate']['description'] = $certDescription;
        $newCert['Certificate']['subject_dn'] = $dn;

        $newJobHistoryRecord = array();
        $newJobHistoryRecord['CoJobHistoryRecord']['co_job_id'] = $CoJob->id;
        $newJobHistoryRecord['CoJobHistoryRecord']['record_key'] = $coPersonId;
        $newJobHistoryRecord['CoJobHistoryRecord']['co_person_id'] = $coPersonId;

        $newHistoryRecord = array();
        $newHistoryRecord['HistoryRecord']['co_person_id'] = $coPersonId;
        $newHistoryRecord['HistoryRecord']['action'] = 'XCIA';
        $newHistoryRecord['HistoryRecord']['comment'] = "$certDescription added";

        if(!$CoPerson->Certificate->save($newCert)) {
          $newJobHistoryRecord['CoJobHistoryRecord']['comment'] = "Unable to add DN $dn";
          $newJobHistoryRecord['CoJobHistoryRecord']['status'] = JobStatusEnum::Failed;
        } else {
          $newJobHistoryRecord['CoJobHistoryRecord']['comment'] = "Added DN " . $dn;
          $newJobHistoryRecord['CoJobHistoryRecord']['status'] = JobStatusEnum::Complete;
          $CoPerson->HistoryRecord->save($newHistoryRecord);
        }
        $CoPerson->CoJobHistoryRecord->save($newJobHistoryRecord);
      }
    }

    // Mark job as completed.
    $CoJob->finish($CoJob->id, "", JobStatusEnum::Complete);
    return;
  }

  /**
   * Obtain the list of parameters supported by this Job.
   *
   * @since  COmanage Registry v3.3.0
   * @return Array Array of supported parameters.
   */
  public function parameterFormat() {

    $params = array();

    $params['certauthid']['help'] = 'CertificateAuthenticator ID';
    $params['certauthid']['required'] = true;
    $params['certauthid']['type'] = 'int';

    return $params;
  }
}
