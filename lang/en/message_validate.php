<?php
return [
    'required' => 'The :attribute field is required.',
    'email' => 'The :attribute must be a valid email address.',

    'attributes' => [
        'username' => 'Email',
        'password' => 'Password',
        'doanhnghiep_mst' => 'Tax code',
        'doanhnghiep_mst_template' => 'Tax code',
        'cyberbill_business_location_code' => 'Business location code',
        'cyberbill_store_code' => 'Store code',
        'cyberbill_store_name' => 'Store name',
    ],

    //custom
    'bill_info_required' => 'Please select an invoice template',
    'check_info_exist' => 'The provider already exists',
    'store_info_not_found' => 'Store information not found for this user',
    'create_enterprise_failed' => 'Failed to create enterprise information',
    'update_enterprise_failed' => 'Failed to update enterprise information in the store',
    'create_invoice_provider_failed' => 'Failed to create invoice provider',
    'get_enterprise_failed' => 'Failed to retrieve enterprise information',
    'get_credentials_failed' => 'Failed to retrieve authentication credentials',
    'create_enterprise_success' => 'Enterprise created successfully',
    'business_info_not_updated' => 'Business information has not been updated',
    'business_not_registered' => 'The enterprise has not registered for e-invoicing',
    'cannot_refresh_token' => 'Unable to refresh authentication token',
    'get_invoice_info_success' => 'Successfully retrieved invoice symbol and form number',
    'get_invoice_info_failed' => 'Failed to retrieve invoice symbol and form number from Cyberbill',
    'api_invoice_info_failed' => 'API error: failed to retrieve invoice symbol and form number',
    'refresh_token_failed' => 'Error refreshing authentication token',
    'update_invoice_template_failed' => 'Failed to update invoice template',
    'update_invoice_template_success' => 'Invoice template updated successfully',
    'provider_default_not_found' => 'Default provider not found',
    'update_provider_default_failed' => 'Failed to update default provider',
    'update_provider_default_success' => 'Default provider updated successfully',
    'enterprise_not_found_for_store' => 'Enterprise not found for this store',
    'invoice_provider_not_found_for_enterprise' => 'Invoice provider not found for this enterprise',
    'delete_invoice_provider_success' => 'Invoice provider deleted successfully',
    'delete_invoice_provider_failed' => 'Failed to delete invoice provider',
    'delete_invoice_provider_error' => 'An error occurred while deleting the invoice provider',
    //response active cyber
    'Tài khoản không đúng thông tin' => 'Incorrect account information',
    'Không tồn tại người dùng trên bill!' => 'User does not exist on bill!',
    'Không tìm thấy tài khoản với id' => 'Account with the specified ID not found',
    //response active viettel
    'Invalid User Name and Password' => 'Invalid User Name and Password',
    //delete
    'invoice_provider_in_use' => 'Cannot delete because there are invoices using this provider',
    //eclectronicbill
    'bill_not_exist' => 'Invoice not found',
    'error_invoice_viettel' => 'Unable to issue electronic invoice from Viettel, please try again later.',
    'vat_invalid' => 'Invalid tax format.',  
    'requiredvat' => ' Tax rate is only allowed to be 0%, 5%, 8%, or 10%',
    'STT' => 'No.'

];

