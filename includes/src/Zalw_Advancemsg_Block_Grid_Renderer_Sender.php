<?php
/**
 * @category    Zalw
 * @package     Zalw_Advancemsg
 * @author      Zalw
 * @use	   	Renders sender name for message grid at frontend
 */
class Zalw_Advancemsg_Block_Grid_Renderer_Sender
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    /**
     * Renders grid column
     *
     * @param   Varien_Object $row
     * @return  string
     */
    public function render(Varien_Object $row)
    {
        $grid = Mage::getBlockSingleton('advancemsg/inbox');
    	$senderId = $row->getData('sender_id');
        $senderType = $row->getData('sender_type');
        if($senderType == 'admin'){
            $admin = Mage::getModel('admin/user')->load($senderId);
            $senderName = $admin['firstname']." ".$admin['lastname'];
            $senderNameType = $senderName."&nbsp;-&nbsp;".$senderType;
        }
        if ($senderType == 'customer'){
            $customer = Mage::getModel('customer/customer')->load($senderId);
	    $senderName = $customer['firstname']." ".$customer['lastname'];
            $senderNameType = $senderName."&nbsp;-&nbsp;".$senderType;
            
        }
        return $senderNameType;
    }
}