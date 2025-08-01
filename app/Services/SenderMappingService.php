<?php

namespace App\Services;

use App\Models\PageSenderMapping;
use Illuminate\Support\Facades\Validator;

class SenderMappingService
{
    public function getAll()
    {
        return PageSenderMapping::orderBy('id')->get();
    }

    public function store(array $data)
    {
        $validator = Validator::make($data, [
            'PAGE' => 'required|string',
            'SENDER_NAME' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        return PageSenderMapping::create($data);
    }

    public function update(int $id, string $column, string $value)
    {
        $mapping = PageSenderMapping::findOrFail($id);
        $mapping->$column = $value;
        $mapping->save();

        return $mapping;
    }

    public function delete(int $id)
    {
        return PageSenderMapping::destroy($id);
    }
}
