<?php

return [
    'required' => ':attribute は必須項目です。',
    'email' => ':attribute の形式が正しくありません。',

    'attributes' => [
        'username' => 'メールアドレス',
        'password' => 'パスワード',
        'doanhnghiep_mst' => '税コード',
        'doanhnghiep_mst_template' => '税コード',
        'cyberbill_business_location_code' => '事業所コード',
        'cyberbill_store_code' => '店舗コード',
        'cyberbill_store_name' => '店舗名',
    ],
    //custom
    'bill_info_required' => '請求書の様式を選択してください',
    'check_info_exist' => 'このプロバイダーは既に存在します',
    'store_info_not_found' => 'このユーザーの店舗情報が見つかりません',
    'create_enterprise_failed' => '企業情報の作成に失敗しました',
    'update_enterprise_failed' => '店舗への企業情報の更新に失敗しました',
    'create_invoice_provider_failed' => '請求書プロバイダーの作成に失敗しました',
    'get_enterprise_failed' => '企業情報の取得に失敗しました',
    'get_credentials_failed' => '認証情報の取得に失敗しました',
    'create_enterprise_success' => '企業が正常に作成されました',
    'business_info_not_updated' => '企業情報がまだ更新されていません',
    'business_not_registered' => '企業は電子請求書を登録していません',
    'cannot_refresh_token' => '認証トークンを再取得できません',
    'get_invoice_info_success' => '請求書の記号と様式番号を正常に取得しました',
    'get_invoice_info_failed' => 'Cyberbillから請求書の記号と様式番号の取得に失敗しました',
    'api_invoice_info_failed' => 'APIエラー: 請求書の記号と様式番号の取得に失敗しました',
    'refresh_token_failed' => '認証トークンの再取得に失敗しました',
    'update_invoice_template_failed' => '請求書の様式の更新に失敗しました',
    'update_invoice_template_success' => '請求書の様式を正常に更新しました',
    'provider_default_not_found' => 'デフォルトのプロバイダーが見つかりません',
    'update_provider_default_failed' => 'デフォルトのプロバイダーの更新に失敗しました',
    'update_provider_default_success' => 'デフォルトのプロバイダーを正常に更新しました',
    'enterprise_not_found_for_store' => 'この店舗に紐づく企業が見つかりません',
    'invoice_provider_not_found_for_enterprise' => 'この企業の請求書プロバイダーが見つかりません',
    'delete_invoice_provider_success' => '請求書プロバイダーを正常に削除しました',
    'delete_invoice_provider_failed' => '請求書プロバイダーの削除に失敗しました',
    'delete_invoice_provider_error' => '請求書プロバイダーの削除中にエラーが発生しました',
    //response active cyber
    'Tài khoản không đúng thông tin' => 'アカウント情報が正しくありません',
    'Không tồn tại người dùng trên bill!' => 'Bill上にユーザーが存在しません',
    'Không tìm thấy tài khoản với id' => '指定されたIDのアカウントが見つかりません',
    //response active viettel
    'Invalid User Name and Password' => 'ユーザー名またはパスワードが正しくありません',
    //delete
    'invoice_provider_in_use' => 'この仕入先は既に請求書で使用されているため、削除できません',
    //eclectronicbill
    'bill_not_exist' => '請求書が見つかりません',
    'error_invoice_viettel' => 'Viettelの電子インボイスを発行できません。時間をおいて再度お試しください。',
    'vat_invalid' => 'VATの形式が正しくありません',
    'requiredvat' => ' の税率は 0%、5%、8%、または 10% のみ許可されています。',
    'STT' => '番号'
];

