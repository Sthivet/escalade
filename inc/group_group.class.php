<?php

/**
 * -------------------------------------------------------------------------
 * Escalade plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Escalade.
 *
 * Escalade is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Escalade is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Escalade. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2015-2023 by Escalade plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/escalade
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginEscaladeGroup_Group extends CommonDBRelation {
   // From CommonDBRelation
   static public $itemtype_1   = 'Group';
   static public $items_id_1   = 'groups_id_source';

   static public $itemtype_2   = 'Group';
   static public $items_id_2   = 'groups_id_destination';

   function getForbiddenStandardMassiveAction() {
      $forbidden   = parent::getForbiddenStandardMassiveAction();
      $forbidden[] = 'update';
      return $forbidden;
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item instanceof Group) {
         return __("Escalation", "escalade");
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item instanceof Group) {
         $PluginEscaladeGroup_Group = new PluginEscaladeGroup_Group();
           $PluginEscaladeGroup_Group->manageGroup($item->getID());
      }
      return true;
   }


   function manageGroup($groups_id) {
      global $CFG_GLPI;

      $group = new Group();
      $rand  = mt_rand();

      $gg_found = $this->find(['groups_id_source' => $groups_id]);
      $nb = count($gg_found);

      echo "<h2>Escalade</h2>";
      if (Session::haveRight('group', UPDATE)) {
         echo "<form method='post' id='manageGroup' action='".PluginEscaladeGroup_Group::getFormURL()."'>";
         $groups_id_used = [];
         foreach ($gg_found as $gg) {
            $groups_id_used[] = $gg['groups_id_destination'];
         }

         Dropdown::show('Group', ['name'      => 'groups_id_destination',
                                  'condition' => ['is_assign' => 1],
                                  'used'      => $groups_id_used]);

         echo Html::hidden('groups_id_source', ['value' => $groups_id]);
         echo Html::submit(_sx('button', 'Add'), ['name' => 'addgroup']);
         Html::closeForm();
      }

      if (Session::haveRight('group', UPDATE) && $nb) {
         Html::openMassiveActionsForm('mass'.__CLASS__.$rand);
         $massiveactionparams = [
            'num_displayed'    => min($nb, $_SESSION['glpilist_limit']),
            'container'        => 'mass'.__CLASS__.$rand
         ];
         Html::showMassiveActions($massiveactionparams);
      }

      echo "<table class='tab_cadre_fixe'>";
      echo "<tr>
         <th width='20'>".Html::getCheckAllAsCheckbox('mass'.__CLASS__.$rand)."</th>
         <th>".__('group')."</th>
      </tr>";

      foreach ($gg_found as $gg_id => $gg) {
         $group->getFromDB($gg['groups_id_destination']);
         echo "<tr class='tab_bg_1'>";
         echo "<td>";
         if (Session::haveRight('group', UPDATE)) {
            Html::showMassiveActionCheckBox(__CLASS__, $gg_id);
         }
         echo "</td>";
         echo "<td>".$group->getLink(true)."</td>";
         echo "</tr>";
      }

      echo "</table>";
      if (Session::haveRight('group', UPDATE) && $nb) {
         if ($nb > 10) {
            $massiveactionparams['ontop'] = false;
            Html::showMassiveActions($massiveactionparams);
         }
         Html::closeForm();
      }
   }

   function getGroups($ticket_id, $removeAlreadyAssigned = true) {
      $groups = $user_groups = $assign_user_groups = [];
      // get groups for user connected
      $tmp_user_groups  = Group_User::getUserGroups($_SESSION['glpiID']);
      foreach ($tmp_user_groups as $current_group) {
         $user_groups[$current_group['id']] = $current_group['id'];
          if ($current_group['is_assign']) {
              $assign_user_groups[$current_group['id']] = $current_group['id'];
          }
      }
      // get groups already assigned in the ticket
      if ($ticket_id > 0) {
         $ticket = new Ticket();
         $ticket->getFromDB($ticket_id);
         $ticket_groups = [];
         foreach ($ticket->getGroups(CommonITILActor::ASSIGN) as $current_group) {
             $ticket_groups[$current_group['groups_id']] = $current_group['groups_id'];
         }
          //No group assigned to the ticket, list user assign group
          if (count($ticket_groups) == 0) {
              $groups = $assign_user_groups;
          }
      }

      // To do an escalation, the user must be in a group currently assigned to the ticket
      // or no group is assigned to the ticket
      // TODO : matching with "view all tickets (yes/no) option in profile user"
      if (!empty($groups) && count(array_intersect($groups, $user_groups)) == 0) {
         return [];
      }
       //get all group which we can climb
       if ($_SESSION['plugins']['escalade']['config']['use_filter_assign_group']) {
           if (count($user_groups) > 0) {
               $group_group = $this->find(['groups_id_source' => $user_groups]);
               foreach ($group_group as $current_group) {
                   $groups[$current_group['groups_id_destination']] = $current_group['groups_id_destination'];
               }
           }
       } else {
           //Retrieve all assignable groups
           $criteria = [
               'is_assign' => 1
           ];
           $all = getAllDataFromTable(Group::getTable(), $criteria);
           foreach ($all as $current_group) {
               $groups[$current_group['id']] = $current_group['id'];
           }
       }

      //remove already assigned groups
      if (!empty($ticket_groups) && $removeAlreadyAssigned) {
         $groups = array_diff_assoc($groups, $ticket_groups);
      }

      //add name to returned groups and remove non assignable groups
      $group_obj = new Group;
      foreach ($groups as $groups_id => &$groupname) {
         $group_obj->getFromDB($groups_id);

         //check if we can assign this group
         if ($group_obj->fields['is_assign'] == 0) {
            unset($groups[$groups_id]);
            continue;
         }

         //add name
         $groupname = $group_obj->fields['name'];
      }

      //sort by group name (and keep associative index)
      asort($groups);

      return $groups;
   }
}
