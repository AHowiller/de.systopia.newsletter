<?php
/*------------------------------------------------------------+
| SYSTOPIA Advanced Newsletter Management                     |
| Copyright (C) 2018 SYSTOPIA                                 |
| Author: J. Schuppe (schuppe@systopia.de)                    |
+-------------------------------------------------------------+
| This program is released as free software under the         |
| Affero GPL license. You can redistribute it and/or          |
| modify it under the terms of this license which you         |
| can read by viewing the included agpl.txt or online         |
| at www.gnu.org/licenses/agpl.html. Removal of this          |
| copyright header is strictly prohibited without             |
| written permission from the original author(s).             |
+-------------------------------------------------------------*/

use CRM_Newsletter_ExtensionUtil as E;

/**
 * API callback for "request" call on "NewsletterSubscription" entity.
 *
 * @param $params
 *
 * @return array
 */
function civicrm_api3_newsletter_subscription_request($params) {
  try {
    if (!$profile = CRM_Newsletter_Profile::getProfile($params['profile'])) {
      throw new CiviCRM_API3_Exception(
        E::ts('No profile found with the given name.'),
        'api_error'
      );
    }

    // If a contact hash is given, do not require contact fields.
    if (!empty($params['contact_hash'])) {
      // Validate contact ID and hash. This throws exceptions for invalid hashes.
      $contact = civicrm_api3('Contact', 'getsingle', array(
        'hash' => $params['contact_hash'],
      ));
      // Validate contact hash against given contact ID (in case a hash for an
      // existing contact was given but does not match the given contact ID).
      if ($contact['id'] != $params['contact_id']) {
        throw new CiviCRM_API3_Exception(E::ts('Invalid contact hash for given contact ID.'), 'api_error');
      }

      $contact_id = $contact['id'];
    }
    else {
      $contact_fields = array_intersect_key($params, $profile->getAttribute('contact_fields'));
      foreach ($contact_fields as $field_name => $field_value) {
        if (!$field_value) {
          unset($params[$field_name]);
        }
      }

      // Check for missing mandatory contact fields.
      $missing_contact_fields = array_diff_key(
        array_filter(
          $profile->getAttribute('contact_fields'),
          function ($contact_field) {
            return !empty($contact_field['required']);
          }
        ),
        $params
      );

      // Get or create the contact.
      $contact_data = array_intersect_key($params, $profile->getAttribute('contact_fields'));
      // add xcm profile
      $xcm_profile = $profile->getAttribute('xcm_profile');
      if (!empty($xcm_profile)) {
        $contact_data['xcm_profile'] = $xcm_profile;
      }
      $contact_result = CRM_Newsletter_Utils::getContact($contact_data);
      $contact_id = $contact_result['contact_id'];
    }

    $contact = civicrm_api3('Contact', 'getsingle', array(
      'id' => $contact_id,
    ));
    $contact_hash = civicrm_api3('Contact', 'getsingle', array(
      'id' => $contact_id,
      'return' => array('hash')
    ));
    $contact['hash'] = $contact_hash['hash'];
    $mailing_lists = CRM_Newsletter_Utils::getSubscriptionStatus($contact_id, $profile->getName());

    // Send an e-mail with the opt-in template.
    $optin_url = $profile->getAttribute('optin_url');
    $optin_url = str_replace(
      '[CONTACT_HASH]',
      $contact['hash'],
      $optin_url
    );
    $optin_url = str_replace(
      '[PROFILE]',
      $profile->getName(),
      $optin_url
    );
    $preferences_url = $profile->getAttribute('preferences_url');
    $preferences_url = str_replace(
      '[CONTACT_HASH]',
      $contact['hash'],
      $preferences_url
    );
    $preferences_url = str_replace(
      '[PROFILE]',
      $profile->getName(),
      $preferences_url
    );
    $mail_params = array(
      'from' => CRM_Newsletter_Utils::getFromEmailAddress(TRUE),
      'toName' => $contact['display_name'],
      'toEmail' => $contact['email'],
      'cc' => '',
      'bc' => '',
      'subject' => $profile->getAttribute('template_optin_subject'),
      'text' => CRM_Core_Smarty::singleton()->fetchWith(
        'string:' . $profile->getAttribute('template_optin'),
        array(
          'contact' => $contact,
          'mailing_lists' => $mailing_lists,
          'optin_url' => $optin_url,
          'preferences_url' => $preferences_url,
        )
      ),
      'html' => CRM_Core_Smarty::singleton()->fetchWith(
        'string:' . $profile->getAttribute('template_optin_html'),
        array(
          'contact' => $contact,
          'mailing_lists' => $mailing_lists,
          'optin_url' => $optin_url,
          'preferences_url' => $preferences_url,
        )
      ),
      'replyTo' => '', // TODO: Make configurable?
    );
    if (!CRM_Utils_Mail::send($mail_params)) {
      // TODO: Mail not sent. Maybe do not cancel the whole API call?
    }

    return civicrm_api3_create_success();
  }
  catch (Exception $exception) {
    $error_code = ($exception instanceof CiviCRM_API3_Exception ? $exception->getErrorCode() : $exception->getCode());
    return civicrm_api3_create_error($exception->getMessage(), array('error_code' => $error_code));
  }
}

/**
 * API specification for "request" call on "NewsletterSubscription" entity.
 *
 * @param $params
 */
function _civicrm_api3_newsletter_subscription_request_spec(&$params) {
  $params['profile'] = array(
    'name' => 'profile',
    'title' => 'Newsletter profile name',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'api.default' => 'default',
    'description' => 'The Newsletter profile name. If omitted, the default profile will be used.',
  );
  $params['contact_hash'] = array(
    'name' => 'contact_hash',
    'title' => 'Contact hash',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'description' => 'The CiviCRM hash of the contact to request a link for.',
  );
  $params['contact_id'] = array(
    'name' => 'contact_id',
    'title' => 'Contact ID',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 0,
    'description' => 'The CiviCRM ID of the contact to request a link for.',
  );

  foreach (CRM_Newsletter_Profile::availableContactFields() as $field_name => $field_label) {
    $params[$field_name] = array(
      'name' => $field_name,
      'title' => $field_label,
      'type' => CRM_Utils_Type::T_STRING,
      'api.required' => 0,
    );
  }
}
