<?php

/*
 * Bản dịch tối thiểu cho các message validation bật thường xuất hiện.
 * Đầy đủ tra tại github.com/Laravel-Lang/lang; chỉ dịch phần đang dùng.
 */

return [
    'required' => 'Trường :attribute là bắt buộc.',
    'string' => ':attribute phải là chuỗi.',
    'numeric' => ':attribute phải là số.',
    'integer' => ':attribute phải là số nguyên.',
    'boolean' => ':attribute phải là true hoặc false.',
    'date' => ':attribute phải là ngày hợp lệ.',
    'email' => ':attribute phải là email hợp lệ.',
    'array' => ':attribute phải là mảng.',
    'file' => ':attribute phải là tệp.',
    'image' => ':attribute phải là ảnh.',
    'in' => 'Giá trị :attribute không hợp lệ.',
    'exists' => ':attribute không tồn tại.',
    'unique' => ':attribute đã tồn tại.',
    'confirmed' => ':attribute không khớp.',
    'max' => [
        'array' => ':attribute không được có quá :max phần tử.',
        'file' => ':attribute không được lớn hơn :max KB.',
        'numeric' => ':attribute không được lớn hơn :max.',
        'string' => ':attribute không được dài hơn :max ký tự.',
    ],
    'min' => [
        'array' => ':attribute phải có ít nhất :min phần tử.',
        'file' => ':attribute phải lớn hơn :min KB.',
        'numeric' => ':attribute phải lớn hơn hoặc bằng :min.',
        'string' => ':attribute phải ít nhất :min ký tự.',
    ],
    'between' => [
        'array' => ':attribute phải có từ :min đến :max phần tử.',
        'file' => ':attribute phải từ :min đến :max KB.',
        'numeric' => ':attribute phải từ :min đến :max.',
        'string' => ':attribute phải từ :min đến :max ký tự.',
    ],
    'mimes' => ':attribute phải là tệp có định dạng: :values.',
    'accepted' => ':attribute phải được chấp nhận.',
    'regex' => 'Định dạng :attribute không hợp lệ.',
    'gt' => [
        'numeric' => ':attribute phải lớn hơn :value.',
        'string' => ':attribute phải dài hơn :value ký tự.',
    ],
    'lt' => [
        'numeric' => ':attribute phải nhỏ hơn :value.',
        'string' => ':attribute phải ngắn hơn :value ký tự.',
    ],
    'custom' => [],
    'attributes' => [],
];
