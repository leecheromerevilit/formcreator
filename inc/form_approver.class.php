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

      // Not working for some reason
      // $request = [
      //       'SELECT' => [
      //          $formApproverTable => ['answer_user_id'],
      //          $user => ['id', 'status', 'request_date'],
      //       ],
      //       'FROM' => $formApproverTable,
      //       'INNER JOIN' => [
      //          $user => [
      //             'FKEY' => [
      //                $formApproverTable => "answer_user_id",
      //                $user => "id"
      //             ]
      //          ]
      //       ],
      //       'WHERE' => [
      //          "$formApproverTable.id" => $formAnswerId
      //       ],
      //    ];

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
      
      // $request = $DB->request([
      //    'SELECT'       => [$formApproverTable . '.*', $user . '.*'],
      //    'FROM'         => $formApproverTable,
      //    'INNER JOIN'   => $user,
      //    'ON'           => $formApproverTable . '.answer_user_id' . '=' . $user . '.id',
      //    'WHERE'  => [
      //       $formApproverTable . '.answer_user_id' => $formAnswerId
      //    ]
      // ]);

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

   public function updateApproverFormFormAnswer($answer_user_id, $formAnswerId, $data){
      $approvers  = new self();
      $approver   = $approvers->getApproverFromFormAnswer($answer_user_id, $formAnswerId);

      $approvers->deleteByCriteria(['answer_user_id' => $answer_user_id, 'plugin_formcreator_formanswers_id' => $formAnswerId]);

      $approvers->add([
         'plugin_formcreator_forms_id'          => 1,
         'plugin_formcreator_formanswers_id'    => $formAnswerId,
         'plugin_formcreator_questions_id'      => 1,
         'answer_user_id'                       => $answer_user_id,
         'status'                               => 102,
         'order'                                => 1,
         'question_label'                       => 'Department Head',
         'comments'                             => $data['comment'],
      ]);

      return true;
   }

   
}
