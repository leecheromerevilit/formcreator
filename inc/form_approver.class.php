<?php
/**
 * ---------------------------------------------------------------------
 * Formcreator is a plugin which allows creation of custom forms of
 * easy access.
 * ---------------------------------------------------------------------
 * LICENSE
 *
 * This file is part of Formcreator.
 *
 * Formcreator is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Formcreator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Formcreator. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * @copyright Copyright © 2011 - 2019 Teclib'
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @link      https://github.com/pluginsGLPI/formcreator/
 * @link      https://pluginsglpi.github.io/formcreator/
 * @link      http://plugins.glpi-project.org/#/plugin/formcreator
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginFormcreatorForm_Approver extends CommonDBRelation
{

   // From CommonDBRelation
   static public $itemtype_1          = PluginFormcreatorForm::class;
   static public $items_id_1          = 'plugin_formcreator_forms_id';

   static public $itemtype_2          = 'itemtype';
   static public $items_id_2          = 'items_id';
   static public $checkItem_2_Rights  = self::HAVE_VIEW_RIGHT_ON_ITEM;

   const VALIDATION_NONE  = 0;
   const VALIDATION_USER  = 1;
   const VALIDATION_GROUP = 2;
   const VALIDATION_FROM_FORM_SECTION = 3;

   public function prepareInputForAdd($input) {
      // generate a unique id
      if (!isset($input['uuid'])
          || empty($input['uuid'])) {
         $input['uuid'] = plugin_formcreator_getUuid();
      }

      return $input;
   }

   public static function import(PluginFormcreatorLinker $linker, $input = [], $forms_id = 0) {
      $formFk = PluginFormcreatorForm::getForeignKeyField();
      $input[$formFk] = $forms_id;

      $item = new self();
       /** @var string $idKey key to use as ID (id or uuid) */
       $idKey = 'id';
      if (isset($input['uuid'])) {
         $idKey = 'uuid';
         $itemId = plugin_formcreator_getFromDBByField(
            $item,
            'uuid',
            $input['uuid']
         );
      }
      // Find the validator
      if (!in_array($input['itemtype'], [User::class, Group::class])) {
         return false;
      }
      $linkedItemtype = $input['itemtype'];
      $linkedItem = new $linkedItemtype();
      $crit = [
         'name' => $input['_item'],
      ];
      if (!$linkedItem->getFromDBByCrit($crit)) {
         // validator not found. Let's ignore it
         return false;
      }
      $input['items_id'] = $linkedItem->getID();

      // Add or update the form validator
      $originalId = $input[$idKey];
      if ($itemId !== false) {
         $input['id'] = $itemId;
         $item->update($input);
      } else {
         unset($input['id']);
         $itemId = $item->add($input);
      }
      if ($itemId === false) {
         $typeName = strtolower(self::getTypeName());
         throw new ImportFailureException(sprintf(__('failed to add or update the %1$s %2$s', 'formceator'), $typeName, $input['name']));
      }

      // add the item to the linker
      if (isset($input['uuid'])) {
         $originalId = $input['uuid'];
      }
      $linker->addObject($originalId, $item);

      return $itemId;
   }

   /**
    * Export in an array all the data of the current instanciated validator
    * @param boolean $remove_uuid remove the uuid key
    *
    * @return array the array with all data (with sub tables)
    */
   public function export($remove_uuid = false) {
      if ($this->isNewItem()) {
         return false;
      }

      $validator = $this->fields;

      // remove key and fk
      unset($validator['plugin_formcreator_forms_id']);

      if (is_subclass_of($validator['itemtype'], CommonDBTM::class)) {
         $validator_obj = new $validator['itemtype'];
         if ($validator_obj->getFromDB($validator['items_id'])) {

            // replace id data
            $identifier_field = isset($validator_obj->fields['completename'])
                                 ? 'completename'
                                 : 'name';
            $validator['_item'] = $validator_obj->fields[$identifier_field];
         }
      }
      unset($validator['items_id']);

      // remove ID or UUID
      $idToRemove = 'id';
      if ($remove_uuid) {
         $idToRemove = 'uuid';
      }
      unset($validator[$idToRemove]);

      return $validator;
   }

   /**
    * Get validators of type $itemtype associated to a form 
    *
    * @param PluginFormcreatorForm $form
    * @param string $itemtype
    * @return array array of User or Group objects
    */
    public function getApproversFromFormAnswer($formAnswerId) {
       global $DB;

      $formApproverTable   = PluginFormcreatorForm_Approver::getTable();
      $user                = User::getTable();
      
      $request = [
            'FROM'         => 'glpi_plugin_formcreator_forms_approvers',
            'INNER JOIN'   => [
               'glpi_users'  => [
                  'FKEY'   => [
                     'glpi_plugin_formcreator_forms_approvers' => 'answer_user_id',
                     'glpi_users'   => 'id'
                  ]
               ]
            ],
            'WHERE'        => [
               'glpi_plugin_formcreator_forms_approvers.plugin_formcreator_formanswers_id' => $formAnswerId
            ],
            'ORDERBY'     => 'order ASC'
         ];

      $results = $DB->request($request);

      return $results;
   }

   /**
    * Get specific detail from Approvers table using formAnswerId and answerUserId
    */

   public function getApproverFromFormAnswer($answer_user_id, $formAnswerId){
      global $DB;

      $formApproverTable   = self::getTable();

      $request = $DB->request([
         'SELECT' => ['*'],
         'FROM'   => $formApproverTable,
         'WHERE'  => [
            'plugin_formcreator_formanswers_id' => $formAnswerId,
            'answer_user_id'                    => $answer_user_id
         ]
      ]);

      return $request;
   }

    /**
    * Get previous approver
    * Get specific detail from Approvers table using formAnswerId and answerUserId
    */

    public function getPrevApproverFromFormAnswer($formAnswerId, $prevApproverOrder){
      global $DB;

      $formApproverTable   = self::getTable();

      $request = $DB->request([
         'SELECT' => ['*'],
         'FROM'   => $formApproverTable,
         'WHERE'  => [
            'plugin_formcreator_formanswers_id' => $formAnswerId,
            'order' => $prevApproverOrder
         ]
      ]);

      return $request;
   }

    /**
    * Get next approver
    * Get specific detail from Approvers table using formAnswerId and answerUserId
    */

    public function getNextApproverFromFormAnswer($formAnswerId, $nextApproverOrder){
      global $DB;

      $formApproverTable   = self::getTable();

      $request = $DB->request([
         'SELECT' => ['*'],
         'FROM'   => $formApproverTable,
         'WHERE'  => [
            'plugin_formcreator_formanswers_id' => $formAnswerId,
            'order' => $nextApproverOrder
         ]
      ]);

      return $request;
   }

   /**
    * TODO
    * 1. Add capability to determine user who tag the status as refused
    * 2. Display refuse comment on formanswer
    * 3. Save historical log
    */

   public function updateApproverFormFormAnswer($answer_user_id, $formAnswerId, $data, $formId){
      $approvers     = new self();
      $approver      = $approvers->getApproverFromFormAnswer($answer_user_id, $formAnswerId);
      $approverList  = $approvers->getApproversFromFormAnswer($formAnswerId);

      // const STATUS_WAITING = 101;
      // const STATUS_REFUSED = 102;
      // const STATUS_ACCEPTED = 103;
      // const STATUS_WAITING_PRIOR_APPROVAL = 104;
      // const STATUS_RETURN = 105;

      Session::addMessageAfterRedirect(__($data['status'], 'formcreator'), true, INFO);


      foreach($approver as $app) {
         $currApproverOrder = $app['order'];
      }

      if($data['status'] == 103){
         $approvers->deleteByCriteria(['answer_user_id' => $answer_user_id, 'plugin_formcreator_formanswers_id' => $formAnswerId]);
         $approvers->add([
                  'plugin_formcreator_forms_id'          => (int)$formId,
                  'plugin_formcreator_formanswers_id'    => $formAnswerId,
                  'answer_user_id'                       => $answer_user_id,
                  'status'                               => 103,
                  'order'                                => $currApproverOrder,
                  'comments'                             => $data['comment'],
               ]);
         
         $nextApproverOrder = $currApproverOrder + 1;
         $nextApprover  = $approvers->getNextApproverFromFormAnswer($formAnswerId, $nextApproverOrder);

         foreach($nextApprover as $nxtApp){
            $nxtAnsUserId = $nxtApp['answer_user_id'];
         }
            
         if($nxtAnsUserId != 0){
            $approvers->deleteByCriteria(['plugin_formcreator_formanswers_id' => $formAnswerId, 'order' => $nextApproverOrder]);
            $approvers->add([
               'plugin_formcreator_forms_id'          => (int)$formId,
               'plugin_formcreator_formanswers_id'    => $formAnswerId,
               'answer_user_id'                       => $nxtAnsUserId,
               'status'                               => 101,
               'order'                                => $nextApproverOrder,
               'comments'                             => '',
            ]);
         }
      } else if ($data['status'] == 102){
         foreach($approverList as $app) {
            $approvers->deleteByCriteria(['answer_user_id' => $app['answer_user_id'], 'plugin_formcreator_formanswers_id' => $app['plugin_formcreator_formanswers_id']]);
            $approvers->add([
               'plugin_formcreator_forms_id'          => (int)$formId,
               'plugin_formcreator_formanswers_id'    => $formAnswerId,
               'answer_user_id'                       => $app['answer_user_id'],
               'status'                               => 102,
               'order'                                => $app['order'],
               'comments'                             => $data['comment'],
            ]);
         }
      } else if ($data['status'] == 105){
         if($currApproverOrder != 1){
            $prevApprover  = $approvers->getNextApproverFromFormAnswer($formAnswerId, $currApproverOrder - 1);
            foreach($prevApprover as $prevApp){
               $approvers->deleteByCriteria(['plugin_formcreator_formanswers_id' => $formAnswerId, 'order' =>  $currApproverOrder - 1]);
               $approvers->add([
                  'plugin_formcreator_forms_id'          => (int)$formId,
                  'plugin_formcreator_formanswers_id'    => $formAnswerId,
                  'answer_user_id'                       => $prevApp['answer_user_id'],
                  'status'                               => 101,
                  'order'                                => $prevApp['order'],
                  'comments'                             => '',
               ]);
            }
         }
         $approvers->deleteByCriteria(['answer_user_id' => $answer_user_id, 'plugin_formcreator_formanswers_id' => $formAnswerId]);
         $approvers->add([
                  'plugin_formcreator_forms_id'          => (int)$formId,
                  'plugin_formcreator_formanswers_id'    => $formAnswerId,
                  'answer_user_id'                       => $answer_user_id,
                  'status'                               => 104,
                  'order'                                => $currApproverOrder,
                  'comments'                             => $data['comment'],
               ]);
      }

      return true;
   }

   
}
