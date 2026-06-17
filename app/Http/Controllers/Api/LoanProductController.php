<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoanProductController extends Controller
{
    /**
     * Get all active loan products (publicly readable for agents to create loans)
     */
    public function index()
    {
        $products = LoanProduct::where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ], 200);
    }

    /**
     * Get all products including inactive ones (Admin only)
     */
    public function indexAdmin()
    {
        $products = LoanProduct::all();

        return response()->json([
            'success' => true,
            'data' => $products
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'                => 'required|string|unique:loan_products',
            'description'         => 'nullable|string',
            'principal_amount'    => 'required|numeric|min:1',
            'expected_amount_to_pay' => 'required|numeric|gt:principal_amount',
            'duration_days'       => 'required|integer|min:1',
            'repayment_frequency' => 'required|in:daily,weekly,bi-weekly,monthly',
            'is_active'           => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        $data = $request->all();
        $data['interest_rate'] = (($data['expected_amount_to_pay'] - $data['principal_amount']) / $data['principal_amount']) * 100;

        $product = LoanProduct::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Loan product created successfully',
            'data'    => $product
        ], 201);
    }

    public function show($id)
    {
        $product = LoanProduct::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Loan product not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $product
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $product = LoanProduct::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Loan product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'                => 'string|unique:loan_products,name,' . $id,
            'description'         => 'nullable|string',
            'principal_amount'    => 'numeric|min:1',
            'expected_amount_to_pay' => 'numeric',
            'duration_days'       => 'integer|min:1',
            'repayment_frequency' => 'in:daily,weekly,bi-weekly,monthly',
            'is_active'           => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name', 'description', 'principal_amount', 'expected_amount_to_pay',
            'duration_days', 'repayment_frequency', 'is_active'
        ]);

        $principal = $request->has('principal_amount') ? $request->principal_amount : $product->principal_amount;
        $expected = $request->has('expected_amount_to_pay') ? $request->expected_amount_to_pay : $product->expected_amount_to_pay;

        if ($principal > 0 && $expected !== null) {
            $data['interest_rate'] = (($expected - $principal) / $principal) * 100;
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Loan product updated successfully',
            'data'    => $product
        ], 200);
    }

    public function destroy($id)
    {
        $product = LoanProduct::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Loan product not found'
            ], 404);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Loan product deleted successfully'
        ], 200);
    }
}
