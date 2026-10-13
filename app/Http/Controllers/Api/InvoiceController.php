<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TermsAndCondition;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * GET/POST: Fetch company details and terms & conditions for an invoice.
     * 
     * - Company details are ALWAYS returned as-is from settings.
     * - Terms and conditions are filtered product_id and project_id wise.
     */
    public function getInvoiceDetails(Request $request)
    {
        // 1. Company details (Always shown as it is from settings table)
        $companyDetails = SettingController::getCompanyDetails();

        // 2. Fetch parameters for terms & conditions
        $productId = $request->query('product_id', $request->input('product_id'));
        $projectId = $request->query('project_id', $request->input('project_id'));

        $termsRecord = null;
        $termsList = [];

        if (!empty($productId) || !empty($projectId)) {
            // First check if exact match for product_id and project_id exists
            if (!empty($productId) && !empty($projectId)) {
                $termsRecord = TermsAndCondition::where('product_id', $productId)
                    ->where('project_id', $projectId)
                    ->where('status', 'Active')
                    ->with(['product:id,title', 'project:id,name'])
                    ->first();

                // If not found, check product-wide terms (where project_id is null)
                if (!$termsRecord) {
                    $termsRecord = TermsAndCondition::where('product_id', $productId)
                        ->whereNull('project_id')
                        ->where('status', 'Active')
                        ->with(['product:id,title', 'project:id,name'])
                        ->first();
                }
            } elseif (!empty($productId)) {
                $termsRecord = TermsAndCondition::where('product_id', $productId)
                    ->where('status', 'Active')
                    ->with(['product:id,title', 'project:id,name'])
                    ->first();
            } elseif (!empty($projectId)) {
                $termsRecord = TermsAndCondition::where('project_id', $projectId)
                    ->where('status', 'Active')
                    ->with(['product:id,title', 'project:id,name'])
                    ->first();
            }

            if ($termsRecord) {
                if (is_array($termsRecord->title)) {
                    $termsList = array_values($termsRecord->title);
                } elseif (!empty($termsRecord->title)) {
                    $termsList = [$termsRecord->title];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'company_details'      => $companyDetails,
                'terms_and_conditions' => $termsRecord,
                'terms'                => $termsList,
            ],
        ], 200);
    }
}
