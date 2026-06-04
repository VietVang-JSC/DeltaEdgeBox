<?php

return [
    'required' => ':attribute là bắt buộc nhập.',
    'email' => ':attribute không hợp lệ.',
    'attributes' => [
        'username' => 'Email',
        'password' => 'Mật khẩu',
        'doanhnghiep_mst' => 'Mã số thuế',
        'doanhnghiep_mst_template' => 'Mã số thuế',
        'cyberbill_business_location_code' => 'Mã địa điểm kinh doanh',
        'cyberbill_store_code' => 'Mã cửa hàng',
        'cyberbill_store_name' => 'Tên cửa hàng',
    ],
    //custom
    'bill_info_required' => 'Vui lòng chọn mẫu hóa đơn',
    'check_info_exist' => 'Nhà cung cấp đã tồn tại',
    'store_info_not_found' => 'Không tìm thấy thông tin cửa hàng cho người dùng này',
    'create_enterprise_failed' => 'Tạo thông tin doanh nghiệp không thành công',
    'update_enterprise_failed' => 'Cập nhật thông tin doanh nghiệp vào cửa hàng không thành công',
    'create_invoice_provider_failed' => 'Tạo nhà cung cấp hóa đơn không thành công',
    'get_enterprise_failed' => 'Lấy thông tin doanh nghiệp không thành công',
    'get_credentials_failed' => 'Lấy thông tin xác thực không thành công',
    'create_enterprise_success' => 'Tạo doanh nghiệp thành công',
    'business_info_not_updated' => 'Thông tin doanh nghiệp chưa được cập nhật',
    'business_not_registered' => 'Doanh nghiệp chưa đăng ký xuất hóa đơn điện tử',
    'cannot_refresh_token' => 'Không thể gọi lại lấy mã xác thực',
    'get_invoice_info_success' => 'Lấy thông tin ký hiệu và mẫu số hóa đơn thành công',
    'get_invoice_info_failed' => 'Lấy thông tin ký hiệu và mẫu số hóa đơn từ Cyberbill không thành công',
    'api_invoice_info_failed' => 'Lỗi API lấy thông tin ký hiệu và mẫu số hóa đơn không thành công',
    'refresh_token_failed' => 'Lỗi gọi lại lấy mã xác thực',
    'update_invoice_template_failed' => 'Cập nhật mẫu hóa đơn thất bại',
    'update_invoice_template_success' => 'Cập nhật mẫu hóa đơn thành công',
    'provider_default_not_found' => 'Không tìm thấy nhà cung cấp mặc định',
    'update_provider_default_failed' => 'Cập nhật nhà cung cấp mặc định không thành công',
    'update_provider_default_success' => 'Cập nhật nhà cung cấp mặc định thành công',
    'enterprise_not_found_for_store' => 'Không tìm thấy doanh nghiệp cho cửa hàng này',
    'invoice_provider_not_found_for_enterprise' => 'Không tìm thấy nhà cung cấp hóa đơn cho doanh nghiệp',
    'delete_invoice_provider_success' => 'Xóa nhà cung cấp hóa đơn thành công',
    'delete_invoice_provider_failed' => 'Xóa nhà cung cấp hóa đơn không thành công',
    'delete_invoice_provider_error' => 'Có lỗi xảy ra trong quá trình xóa nhà cung cấp hóa đơn',
    //response active cyber
    'Tài khoản không đúng thông tin' => 'Tài khoản không đúng thông tin',
    'Không tồn tại người dùng trên bill!' => 'Không tồn tại người dùng trên bill!',
    'Không tìm thấy tài khoản với id' => 'Không tìm thấy tài khoản với id',
    //response active viettel
    'Invalid User Name and Password' => 'Tên đăng nhập hoặc mật khẩu không hợp lệ',
    //delete
    'invoice_provider_in_use' => 'Không thể xóa vì đã có hóa đơn sử dụng nhà cung cấp này',
    //eclectronicbill
    'bill_not_exist' => 'Không tìm thấy hóa đơn', 
    'error_invoice_viettel' => 'Không thể phát hành hóa đơn điện tử từ Viettel, vui lòng thử lại sau.',
    'vat_invalid' => 'Thuế không đúng định dạng',
    'requiredvat' => ' Thuế xuất chỉ được phép là 0, 5, 8 hoặc 10%',
    'STT' => 'STT'
];














