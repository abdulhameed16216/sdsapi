<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectStep;
use App\Models\ProjectStepDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search');

        $query = Project::with(['subscription', 'customer', 'steps.stepDocuments']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $projects = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $projects
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Manually parse FormData - Laravel might not parse nested arrays correctly
        $allData = [];
        
        // Get all keys from request
        $allKeys = array_keys($request->all());
        
        // Parse simple fields first (name, start_date, etc.)
        $simpleFields = ['name', 'subscription_id', 'customer_id', 'start_date', 'end_date', 'description'];
        foreach ($simpleFields as $field) {
            $value = $request->input($field);
            if ($value !== null) {
                $allData[$field] = $value;
            }
        }
        
        // Manually parse steps array from underscore format: steps_2_step_id, steps_2_description, etc.
        $steps = [];
        foreach ($allKeys as $key) {
            // Match underscore format: steps_2_step_id, steps_2_description, steps_2_documents_0, etc.
            if (preg_match('/^steps_(\d+)_(.+)$/', $key, $matches)) {
                $stepIndex = $matches[1];
                $fieldName = $matches[2];
                
                if (!isset($steps[$stepIndex])) {
                    $steps[$stepIndex] = [];
                }
                
                $value = $request->input($key);
                
                // Handle documents: steps_2_documents_0
                if (preg_match('/^documents_(\d+)$/', $fieldName, $docMatches)) {
                    $docIndex = $docMatches[1];
                    if (!isset($steps[$stepIndex]['documents'])) {
                        $steps[$stepIndex]['documents'] = [];
                    }
                    // Files will be handled separately via allFiles()
                    if (!($value instanceof \Illuminate\Http\UploadedFile)) {
                        $steps[$stepIndex]['documents'][$docIndex] = $value;
                    }
                }
                // Handle existing_documents: steps_2_existing_documents_0_name
                elseif (preg_match('/^existing_documents_(\d+)_(.+)$/', $fieldName, $existingDocMatches)) {
                    $docIndex = $existingDocMatches[1];
                    $docField = $existingDocMatches[2];
                    if (!isset($steps[$stepIndex]['existing_documents'])) {
                        $steps[$stepIndex]['existing_documents'] = [];
                    }
                    if (!isset($steps[$stepIndex]['existing_documents'][$docIndex])) {
                        $steps[$stepIndex]['existing_documents'][$docIndex] = [];
                    }
                    $steps[$stepIndex]['existing_documents'][$docIndex][$docField] = $value;
                }
                // Regular fields: step_id, description, status, completion_date, id
                else {
                    if ($value !== null && !($value instanceof \Illuminate\Http\UploadedFile)) {
                        $steps[$stepIndex][$fieldName] = $value;
                    }
                }
            }
        }
        
        if (!empty($steps)) {
            $allData['steps'] = $steps;
        }
        
        // Merge files for processing - handle underscore format: steps_2_documents_0
        $allFiles = $request->allFiles();
        foreach ($allFiles as $key => $file) {
            // Match files in underscore format: steps_2_documents_0
            if (preg_match('/^steps_(\d+)_documents_(\d+)$/', $key, $fileMatches)) {
                $stepIndex = $fileMatches[1];
                $docIndex = $fileMatches[2];
                if (!isset($allData['steps'][$stepIndex]['documents'])) {
                    $allData['steps'][$stepIndex]['documents'] = [];
                }
                $allData['steps'][$stepIndex]['documents'][$docIndex] = $file;
            } else {
                // Other files (if any)
                $allData[$key] = $file;
            }
        }

        $validator = Validator::make($allData, [
            'name' => 'required|string|max:255',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'customer_id' => 'nullable|exists:customers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'description' => 'nullable|string',
            'steps' => 'nullable|array',
            'steps.*.step_id' => 'nullable|integer|min:2|max:5',
            'steps.*.description' => 'nullable|string',
            'steps.*.status' => 'nullable|string|in:pending,in_progress,completed',
            'steps.*.completion_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $project = Project::create([
            'name' => $allData['name'] ?? null,
            'subscription_id' => $allData['subscription_id'] ?? null,
            'customer_id' => $allData['customer_id'] ?? null,
            'start_date' => $allData['start_date'] ?? null,
            'end_date' => $allData['end_date'] ?? null,
            'description' => $allData['description'] ?? null,
        ]);

        // Process steps if provided
        if (isset($allData['steps']) && is_array($allData['steps'])) {
            $this->processSteps($project, $allData['steps'], $request);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project created successfully',
            'data' => $project->fresh(['subscription', 'customer', 'steps.stepDocuments'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $project = Project::with(['subscription', 'customer', 'steps.stepDocuments'])->find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $project
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $project = Project::find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        // For PUT with FormData, Laravel doesn't parse multipart/form-data automatically
        // We need to manually parse it from the request
        $allData = [];
        
        // Check if this is a multipart/form-data request
        $contentType = $request->header('Content-Type', '');
        $isMultipart = strpos($contentType, 'multipart/form-data') !== false;
        $requestData = $request->all();
        
        // If multipart and Laravel didn't parse it (empty $requestData), parse manually
        if ($isMultipart && empty($requestData)) {
            // For PUT with FormData, try to get from Symfony's request->request ParameterBag
            // Symfony's Request might have parsed it
            $symfonyData = $request->request->all();
            
            // Debug log
            \Log::info('PUT FormData Debug:', [
                'symfony_request_all' => $symfonyData,
                'symfony_keys' => array_keys($symfonyData),
                'request_all' => $requestData,
            ]);
            
            if (!empty($symfonyData)) {
                // Data is in Symfony's request bag
                $parsedData = $symfonyData;
            } else {
                // Laravel/Symfony didn't parse it - this is a known limitation
                // Solution: Use POST with _method=PUT or parse manually
                \Log::error('PUT FormData not parsed. Consider using POST with _method=PUT header.');
                $parsedData = [];
            }
            
            // Parse simple fields
            $simpleFields = ['name', 'subscription_id', 'customer_id', 'start_date', 'end_date', 'description'];
            foreach ($simpleFields as $field) {
                if (isset($parsedData[$field]) && $parsedData[$field] !== '') {
                    $allData[$field] = $parsedData[$field];
                }
            }
            
            // Parse steps array from underscore format: steps_2_step_id, steps_2_description, etc.
            $steps = [];
            foreach ($parsedData as $key => $value) {
                // Match underscore format: steps_2_step_id, steps_2_description, steps_2_documents_0, etc.
                if (preg_match('/^steps_(\d+)_(.+)$/', $key, $matches)) {
                    $stepIndex = $matches[1];
                    $fieldName = $matches[2];
                    
                    if (!isset($steps[$stepIndex])) {
                        $steps[$stepIndex] = [];
                    }
                    
                    // Handle documents: steps_2_documents_0, steps_2_documents_1, etc.
                    if (preg_match('/^documents_(\d+)$/', $fieldName, $docMatches)) {
                        $docIndex = $docMatches[1];
                        if (!isset($steps[$stepIndex]['documents'])) {
                            $steps[$stepIndex]['documents'] = [];
                        }
                        // Files will be handled separately via allFiles()
                        // Just store the index for reference
                        $steps[$stepIndex]['documents'][$docIndex] = $value;
                    }
                    // Handle existing_documents: steps_2_existing_documents_0_name, etc.
                    elseif (preg_match('/^existing_documents_(\d+)_(.+)$/', $fieldName, $existingDocMatches)) {
                        $docIndex = $existingDocMatches[1];
                        $docField = $existingDocMatches[2];
                        if (!isset($steps[$stepIndex]['existing_documents'])) {
                            $steps[$stepIndex]['existing_documents'] = [];
                        }
                        if (!isset($steps[$stepIndex]['existing_documents'][$docIndex])) {
                            $steps[$stepIndex]['existing_documents'][$docIndex] = [];
                        }
                        $steps[$stepIndex]['existing_documents'][$docIndex][$docField] = $value;
                    }
                    // Regular fields: step_id, description, status, completion_date, id
                    else {
                        $steps[$stepIndex][$fieldName] = $value;
                    }
                }
            }
            
            if (!empty($steps)) {
                $allData['steps'] = $steps;
            }
            
            // Get files from request - handle underscore format: steps_2_documents_0
            $allFiles = $request->allFiles();
            foreach ($allFiles as $key => $file) {
                // Match files in underscore format: steps_2_documents_0
                if (preg_match('/^steps_(\d+)_documents_(\d+)$/', $key, $fileMatches)) {
                    $stepIndex = $fileMatches[1];
                    $docIndex = $fileMatches[2];
                    if (!isset($allData['steps'][$stepIndex]['documents'])) {
                        $allData['steps'][$stepIndex]['documents'] = [];
                    }
                    $allData['steps'][$stepIndex]['documents'][$docIndex] = $file;
                } else {
                    // Other files (if any)
                    $allData[$key] = $file;
                }
            }
        } else {
            // Standard parsing (works for POST or if PUT already parsed)
            $simpleFields = ['name', 'subscription_id', 'customer_id', 'start_date', 'end_date', 'description'];
            foreach ($simpleFields as $field) {
                $value = $request->input($field);
                if ($value !== null && $value !== '') {
                    $allData[$field] = $value;
                }
            }
            
            // Parse steps from underscore format
            $steps = [];
            $allKeys = array_keys($requestData);
            
            foreach ($allKeys as $key) {
                // Match underscore format: steps_2_step_id, steps_2_description, etc.
                if (preg_match('/^steps_(\d+)_(.+)$/', $key, $matches)) {
                    $stepIndex = $matches[1];
                    $fieldName = $matches[2];
                    
                    if (!isset($steps[$stepIndex])) {
                        $steps[$stepIndex] = [];
                    }
                    
                    $value = $request->input($key);
                    
                    // Handle documents: steps_2_documents_0
                    if (preg_match('/^documents_(\d+)$/', $fieldName, $docMatches)) {
                        $docIndex = $docMatches[1];
                        if (!isset($steps[$stepIndex]['documents'])) {
                            $steps[$stepIndex]['documents'] = [];
                        }
                        // Files will be handled separately
                        if (!($value instanceof \Illuminate\Http\UploadedFile)) {
                            $steps[$stepIndex]['documents'][$docIndex] = $value;
                        }
                    }
                    // Handle existing_documents: steps_2_existing_documents_0_name
                    elseif (preg_match('/^existing_documents_(\d+)_(.+)$/', $fieldName, $existingDocMatches)) {
                        $docIndex = $existingDocMatches[1];
                        $docField = $existingDocMatches[2];
                        if (!isset($steps[$stepIndex]['existing_documents'])) {
                            $steps[$stepIndex]['existing_documents'] = [];
                        }
                        if (!isset($steps[$stepIndex]['existing_documents'][$docIndex])) {
                            $steps[$stepIndex]['existing_documents'][$docIndex] = [];
                        }
                        $steps[$stepIndex]['existing_documents'][$docIndex][$docField] = $value;
                    }
                    // Regular fields
                    else {
                        if ($value !== null && !($value instanceof \Illuminate\Http\UploadedFile)) {
                            $steps[$stepIndex][$fieldName] = $value;
                        }
                    }
                }
            }
            
            if (!empty($steps)) {
                $allData['steps'] = $steps;
            }
            
            // Get files - handle underscore format
            $allFiles = $request->allFiles();
            foreach ($allFiles as $key => $file) {
                if (preg_match('/^steps_(\d+)_documents_(\d+)$/', $key, $fileMatches)) {
                    $stepIndex = $fileMatches[1];
                    $docIndex = $fileMatches[2];
                    if (!isset($allData['steps'][$stepIndex]['documents'])) {
                        $allData['steps'][$stepIndex]['documents'] = [];
                    }
                    $allData['steps'][$stepIndex]['documents'][$docIndex] = $file;
                } else {
                    $allData[$key] = $file;
                }
            }
        }

        $validator = Validator::make($allData, [
            'name' => 'required|string|max:255',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'customer_id' => 'nullable|exists:customers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'description' => 'nullable|string',
            'steps' => 'nullable|array',
            'steps.*.step_id' => 'nullable|integer|min:2|max:5',
            'steps.*.description' => 'nullable|string',
            'steps.*.status' => 'nullable|string|in:pending,in_progress,completed',
            'steps.*.completion_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Use data from allData array
        $project->update([
            'name' => $allData['name'] ?? null,
            'subscription_id' => $allData['subscription_id'] ?? null,
            'customer_id' => $allData['customer_id'] ?? null,
            'start_date' => $allData['start_date'] ?? null,
            'end_date' => $allData['end_date'] ?? null,
            'description' => $allData['description'] ?? null,
        ]);

        // Process steps if provided
        if (isset($allData['steps']) && is_array($allData['steps'])) {
            $this->processSteps($project, $allData['steps'], $request);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project updated successfully',
            'data' => $project->fresh(['subscription', 'customer', 'steps.stepDocuments'])
        ]);
    }

    /**
     * Parse steps from FormData structure
     */
    private function parseStepsFromFormData(Request $request)
    {
        $steps = [];
        $all = $request->all();
        $allKeys = array_keys($all);
        
        foreach ($allKeys as $key) {
            // Match underscore format: steps_2_step_id, steps_2_description, etc.
            if (preg_match('/^steps_(\d+)_(.+)$/', $key, $matches)) {
                $stepIndex = $matches[1];
                $fieldName = $matches[2];
                
                if (!isset($steps[$stepIndex])) {
                    $steps[$stepIndex] = [];
                }
                
                $value = $request->input($key) ?? ($all[$key] ?? null);
                
                // Handle documents: steps_2_documents_0
                if (preg_match('/^documents_(\d+)$/', $fieldName, $docMatches)) {
                    $docIndex = $docMatches[1];
                    if (!isset($steps[$stepIndex]['documents'])) {
                        $steps[$stepIndex]['documents'] = [];
                    }
                    if (!($value instanceof \Illuminate\Http\UploadedFile)) {
                        $steps[$stepIndex]['documents'][$docIndex] = $value;
                    }
                }
                // Handle existing_documents: steps_2_existing_documents_0_name
                elseif (preg_match('/^existing_documents_(\d+)_(.+)$/', $fieldName, $existingDocMatches)) {
                    $docIndex = $existingDocMatches[1];
                    $docField = $existingDocMatches[2];
                    if (!isset($steps[$stepIndex]['existing_documents'])) {
                        $steps[$stepIndex]['existing_documents'] = [];
                    }
                    if (!isset($steps[$stepIndex]['existing_documents'][$docIndex])) {
                        $steps[$stepIndex]['existing_documents'][$docIndex] = [];
                    }
                    $steps[$stepIndex]['existing_documents'][$docIndex][$docField] = $value;
                }
                // Regular fields
                else {
                    if ($value !== null && !($value instanceof \Illuminate\Http\UploadedFile)) {
                        $steps[$stepIndex][$fieldName] = $value;
                    }
                }
            }
        }
        
        return $steps;
    }

    /**
     * Parse FormData input to handle nested arrays properly
     * Handles both regular input and FormData nested structure like steps[2][step_id]
     */
    private function parseFormDataInput(Request $request)
    {
        // For PUT/PATCH with FormData, Laravel might not parse automatically
        // Get all data - try different methods
        $all = $request->all();
        $inputMethod = $request->input();
        $postMethod = method_exists($request, 'post') ? $request->post() : [];
        
        // Merge all sources (input takes precedence)
        $allData = array_merge($postMethod, $inputMethod, $all);
        
        // Initialize parsed input
        $input = [];
        
        // Extract simple fields first (name, start_date, etc.)
        $simpleFields = ['name', 'subscription_id', 'customer_id', 'start_date', 'end_date', 'description'];
        foreach ($simpleFields as $field) {
            // Try multiple methods to get the value
            $value = $request->input($field) 
                  ?? $request->get($field)
                  ?? ($allData[$field] ?? null);
            
            if ($value !== null && !($value instanceof \Illuminate\Http\UploadedFile)) {
                $input[$field] = $value;
            }
        }
        
        // Check if steps is already properly parsed as array
        if (isset($allData['steps']) && is_array($allData['steps']) && !empty($allData['steps'])) {
            // Filter out files from steps
            $parsedSteps = [];
            foreach ($allData['steps'] as $index => $step) {
                if (is_array($step)) {
                    $parsedStep = [];
                    foreach ($step as $key => $value) {
                        if (!($value instanceof \Illuminate\Http\UploadedFile)) {
                            $parsedStep[$key] = $value;
                        }
                    }
                    if (!empty($parsedStep)) {
                        $parsedSteps[$index] = $parsedStep;
                    }
                }
            }
            if (!empty($parsedSteps)) {
                $input['steps'] = $parsedSteps;
            }
            return $input;
        }
        
        // Otherwise, manually parse steps from FormData structure: steps[2][step_id], steps[2][id], etc.
        $steps = [];
        $allKeys = array_keys($allData);
        
        foreach ($allKeys as $key) {
            // Match patterns like steps[2][step_id], steps[2][id], etc.
            if (preg_match('/^steps\[(\d+)\]\[(.+)\]$/', $key, $matches)) {
                $stepIndex = $matches[1];
                $stepField = $matches[2];
                
                if (!isset($steps[$stepIndex])) {
                    $steps[$stepIndex] = [];
                }
                
                // Try multiple methods to get the value
                $value = $request->input($key) 
                      ?? $request->get($key)
                      ?? ($allData[$key] ?? null);
                
                if ($value !== null && !($value instanceof \Illuminate\Http\UploadedFile)) {
                    $steps[$stepIndex][$stepField] = $value;
                }
            }
        }
        
        if (!empty($steps)) {
            $input['steps'] = $steps;
        }
        
        return $input;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $project = Project::find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        $project->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project deleted successfully'
        ]);
    }

    /**
     * Process steps for a project (create or update)
     */
    private function processSteps(Project $project, array $steps, Request $request = null)
    {
        // Filter out null values (FormData might send null for empty steps)
        $steps = array_filter($steps, function($step) {
            return $step !== null && is_array($step);
        });
        
        foreach ($steps as $stepIndex => $stepData) {
            // Skip if stepData is not an array or doesn't have step_id
            if (!is_array($stepData) || !isset($stepData['step_id'])) {
                continue;
            }
            
            $stepId = $stepData['step_id'];
            // Validate step_id is between 2 and 5
            if ($stepId < 2 || $stepId > 5) {
                continue; // Skip invalid step_id
            }

            $stepId = $stepData['step_id'];
            $existingStepId = isset($stepData['id']) && !empty($stepData['id']) ? $stepData['id'] : null;

            // Handle file uploads for this step
            // FormData sends files as steps_2_documents_0 (underscore format)
            // Use step_id (2, 3, 4, 5) not array index for file keys
            $uploadedDocuments = [];
            $files = [];
            
            // Method 0: Try underscore format directly FIRST (steps_2_documents_0) - PRIMARY METHOD
            // This is the format we're using from the frontend
            if ($request) {
                for ($i = 0; $i < 10; $i++) {
                    $fileKey = "steps_{$stepId}_documents_{$i}";
                    if ($request->hasFile($fileKey)) {
                        $file = $request->file($fileKey);
                        if (is_array($file)) {
                            $files = array_merge($files, $file);
                        } else {
                            $files[] = $file;
                        }
                    } else {
                        // If we found some files but this index doesn't exist, break
                        if ($i > 0 && !empty($files)) {
                            break;
                        }
                    }
                }
                
                // Also check allFiles() for underscore format (in case hasFile doesn't work)
                if (empty($files)) {
                    $allRequestFiles = $request->allFiles();
                    foreach ($allRequestFiles as $key => $file) {
                        // Match underscore format: steps_2_documents_0
                        if (preg_match('/^steps_' . $stepId . '_documents_(\d+)$/', $key, $matches)) {
                            if (is_array($file)) {
                                $files = array_merge($files, $file);
                            } else {
                                $files[] = $file;
                            }
                        }
                    }
                }
            }
            
            // Method 0.5: Check if files are already in stepData array (from parsed FormData)
            if (empty($files) && isset($stepData['documents']) && is_array($stepData['documents'])) {
                foreach ($stepData['documents'] as $docIndex => $doc) {
                    if ($doc instanceof \Illuminate\Http\UploadedFile) {
                        $files[] = $doc;
                    } elseif (is_array($doc)) {
                        // Handle nested arrays
                        foreach ($doc as $nestedDoc) {
                            if ($nestedDoc instanceof \Illuminate\Http\UploadedFile) {
                                $files[] = $nestedDoc;
                            }
                        }
                    }
                }
            }
            
            if ($request && empty($files)) {
                // Method 1: Try to get files from allFiles() array structure
                $allFiles = $request->allFiles();
                if (isset($allFiles['steps'][$stepId]['documents'])) {
                    $stepFiles = $allFiles['steps'][$stepId]['documents'];
                    if (is_array($stepFiles)) {
                        $files = $stepFiles;
                    } else {
                        $files = [$stepFiles];
                    }
                }
                
                // Method 2: Try dot notation with indices (steps.2.documents.0, steps.2.documents.1, etc.)
                if (empty($files)) {
                    // Check for files with indices 0-10 (should be enough)
                    for ($i = 0; $i < 10; $i++) {
                        $fileKey = "steps.{$stepId}.documents.{$i}";
                        if ($request->hasFile($fileKey)) {
                            $files[] = $request->file($fileKey);
                        } else {
                            // If we found some files but this index doesn't exist, break
                            if ($i > 0 && !empty($files)) {
                                break;
                            }
                        }
                    }
                }
                
                // Method 3: Try bracket notation with indices
                if (empty($files)) {
                    for ($i = 0; $i < 10; $i++) {
                        $fileKey = "steps[{$stepId}][documents][{$i}]";
                        if ($request->hasFile($fileKey)) {
                            $files[] = $request->file($fileKey);
                        } else {
                            if ($i > 0 && !empty($files)) {
                                break;
                            }
                        }
                    }
                }
                
                // Method 4: Try without index (single file)
                if (empty($files)) {
                $fileKeys = [
                        "steps.{$stepId}.documents",
                        "steps[{$stepId}][documents]",
                    "steps.{$stepIndex}.documents",
                    "steps[{$stepIndex}][documents]",
                ];
                
                foreach ($fileKeys as $fileKey) {
                    if ($request->hasFile($fileKey)) {
                            $file = $request->file($fileKey);
                            if (is_array($file)) {
                                $files = $file;
                            } else {
                                $files = [$file];
                        }
                        break;
                        }
                    }
                }
                
                // Method 5: Check the steps array structure directly
                if (empty($files) && $request->has('steps')) {
                    $stepsData = $request->input('steps');
                    if (isset($stepsData[$stepId]['documents']) || isset($stepsData[$stepIndex]['documents'])) {
                        // Files might be in the input, but we need to get them from allFiles
                        $allRequestFiles = $request->allFiles();
                        // Check both stepId and stepIndex
                        $stepKeys = [$stepId, $stepIndex];
                        foreach ($stepKeys as $key) {
                            if (isset($allRequestFiles['steps'][$key]['documents'])) {
                                $stepFiles = $allRequestFiles['steps'][$key]['documents'];
                                if (is_array($stepFiles)) {
                                    $files = array_merge($files, $stepFiles);
                                } else {
                                    $files[] = $stepFiles;
                                }
                            }
                        }
                    }
                }
                
                // Method 6: Try underscore format (steps_2_documents_0, steps_2_documents_1, etc.)
                if (empty($files)) {
                    for ($i = 0; $i < 10; $i++) {
                        $fileKey = "steps_{$stepId}_documents_{$i}";
                        if ($request->hasFile($fileKey)) {
                            $files[] = $request->file($fileKey);
                        } else {
                            // If we found some files but this index doesn't exist, break
                            if ($i > 0 && !empty($files)) {
                                break;
                            }
                        }
                    }
                }
                
                // Method 7: Iterate through all files and find matches for this step by key pattern
                if (empty($files)) {
                    // Get all file keys from the request
                    $allRequestFiles = $request->allFiles();
                    
                    // Check for underscore format in allFiles keys
                    foreach ($allRequestFiles as $key => $file) {
                        // Match underscore format: steps_2_documents_0
                        if (preg_match('/^steps_' . $stepId . '_documents_(\d+)$/', $key, $matches)) {
                            if (is_array($file)) {
                                $files = array_merge($files, $file);
                            } else {
                                $files[] = $file;
                            }
                        }
                    }
                    
                    // If still empty, recursively search for files matching our step pattern
                    if (empty($files)) {
                        $this->extractFilesFromArray($allRequestFiles, $stepId, $files);
                    }
                }
                
                // Filter out non-file objects and ensure we have valid files
                $files = array_filter($files, function($file) {
                    return $file instanceof \Illuminate\Http\UploadedFile;
                });
                
                if (!empty($files)) {
                    // Generate folder name: random alphabets + date_time
                    $randomString = Str::random(8);
                    $dateTime = now()->format('Ymd_His');
                    $folderName = $randomString . '_' . $dateTime;
                    
                    foreach ($files as $fileIndex => $file) {
                        // Validate file type - only allow PDF, PNG, and images
                        $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
                        $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
                        
                        $mimeType = $file->getMimeType();
                        $extension = strtolower($file->getClientOriginalExtension());
                        
                        if (!in_array($mimeType, $allowedMimes) && !in_array($extension, $allowedExtensions)) {
                            continue; // Skip invalid file types
                        }
                        
                        // Use original filename from request, sanitize it
                        $originalName = $file->getClientOriginalName();
                        $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nameWithoutExt);
                        
                        // If sanitized name is empty, use a default
                        if (empty($sanitizedName)) {
                            $sanitizedName = 'document_' . $fileIndex;
                        }
                        
                        // Create unique filename: original_name_timestamp_random.ext
                        $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                        
                        // Store in public folder: public/uploads/project/{project_id}/steps/{step_id}/filename
                        $publicPath = "uploads/project/{$project->id}/steps/{$stepId}";
                        $fullPath = public_path($publicPath);
                        
                        // Create directory if it doesn't exist
                        if (!file_exists($fullPath)) {
                            mkdir($fullPath, 0755, true);
                        }
                        
                        // Move file to public folder
                        $file->move($fullPath, $fileName);
                        
                        // Generate public URL
                        $appUrl = rtrim(config('app.url'), '/');
                        $fileUrl = $appUrl . '/' . $publicPath . '/' . $fileName;
                        $filePath = $publicPath . '/' . $fileName;
                        
                        $uploadedDocuments[] = [
                            'name' => $originalName, // Original filename from request
                            'file_name' => $fileName, // Stored filename
                            'file_path' => $filePath,
                            'file_url' => $fileUrl,
                            'file_size' => $file->getSize(),
                            'mime_type' => $file->getMimeType(),
                        ];
                    }
                }
            }

            // Handle existing documents
            $existingDocs = [];
            $documents = $uploadedDocuments;
            if (isset($stepData['existing_documents']) && is_array($stepData['existing_documents'])) {
                foreach ($stepData['existing_documents'] as $doc) {
                    if (is_array($doc)) {
                        // If file_path is empty but name exists, it's an old string format document
                        if (empty($doc['file_path']) && !empty($doc['name'])) {
                            // Keep as string for backward compatibility
                            $documents[] = $doc['name'];
                        } else {
                            // New format: object with file info
                            $docArray = [
                                'name' => $doc['name'] ?? '',
                                'file_name' => $doc['file_name'] ?? $doc['name'] ?? '',
                                'file_path' => $doc['file_path'] ?? '',
                                'file_url' => $doc['file_url'] ?? '',
                                'file_size' => $doc['file_size'] ?? 0,
                                'mime_type' => $doc['mime_type'] ?? null,
                            ];
                            $documents[] = $docArray;
                            // Also add to existingDocs for saving to table
                            if (!empty($doc['file_path'])) {
                                $existingDocs[] = $docArray;
                            }
                        }
                    }
                }
            } else if ($existingStepId) {
                // Get existing documents from database table if step exists
                $step = ProjectStep::where('project_id', $project->id)->find($existingStepId);
                if ($step) {
                    $existingDocsFromDb = ProjectStepDocument::where('project_step_id', $step->id)->get();
                    foreach ($existingDocsFromDb as $doc) {
                        // Generate correct URL for authenticated access
                        $appUrl = rtrim(config('app.url'), '/');
                        $fileUrl = $appUrl . '/api/projects/documents/' . $doc->file_name;
                        
                        $docArray = [
                            'name' => $doc->name,
                            'file_name' => $doc->file_name,
                            'file_path' => $doc->file_path,
                            'file_url' => $fileUrl,
                            'file_size' => $doc->file_size,
                            'mime_type' => $doc->mime_type,
                        ];
                        $existingDocs[] = $docArray;
                        $documents[] = $docArray;
                    }
                }
            }

            // Create or update step
            if ($existingStepId) {
                // Update existing step
                $step = ProjectStep::where('project_id', $project->id)->find($existingStepId);
                if ($step) {
                    $updateData = [
                        'description' => !empty($stepData['description']) ? $stepData['description'] : ($step->description ?? null),
                        'status' => $stepData['status'] ?? $step->status,
                        'completion_date' => $stepData['completion_date'] ?? null,
                        'documents' => $documents, // Keep for backward compatibility
                    ];
                    $step->update($updateData);
                    
                    // Save documents to project_step_documents table
                    $this->saveStepDocuments($step, $uploadedDocuments, $existingDocs);
                }
            } else {
                // Create new step - always create even if empty
                // Check if step already exists for this project and step_id
                $existingStep = ProjectStep::where('project_id', $project->id)
                    ->where('step_id', $stepId)
                    ->first();
                
                if ($existingStep) {
                    // Update existing step instead of creating duplicate
                    $updateData = [
                        'description' => !empty($stepData['description']) ? $stepData['description'] : ($existingStep->description ?? null),
                        'status' => $stepData['status'] ?? $existingStep->status ?? 'pending',
                        'completion_date' => $stepData['completion_date'] ?? null,
                        'documents' => $documents, // Keep for backward compatibility
                    ];
                    $existingStep->update($updateData);
                    $step = $existingStep;
            } else {
                // Create new step
                    $step = ProjectStep::create([
                    'project_id' => $project->id,
                    'step_id' => $stepId,
                    'description' => !empty($stepData['description']) ? $stepData['description'] : null,
                    'status' => $stepData['status'] ?? 'pending',
                    'completion_date' => $stepData['completion_date'] ?? null,
                        'documents' => $documents, // Keep for backward compatibility
                ]);
                }
                
                // Save documents to project_step_documents table
                $this->saveStepDocuments($step, $uploadedDocuments, $existingDocs);
            }
        }
    }

    /**
     * Add a step to a project.
     */
    public function addStep(Request $request, $id)
    {
        $project = Project::find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        // Custom validation for documents - can be files or array
        $validator = Validator::make($request->all(), [
            'step_id' => 'required|integer|min:2|max:5',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'completion_date' => 'nullable|date',
        ]);

        // Validate documents if provided
        if ($request->hasFile('documents')) {
            $fileValidator = Validator::make($request->all(), [
                'documents.*' => 'file|mimes:pdf,png,jpg,jpeg,gif,webp|max:10240', // Max 10MB - Only PDF, PNG, and images
            ]);
            if ($fileValidator->fails()) {
                $validator->errors()->merge($fileValidator->errors());
            }
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle file uploads
        $uploadedDocuments = [];
        if ($request->hasFile('documents')) {
            $files = $request->file('documents');
            
            // Generate folder name: random alphabets + date_time
            $randomString = Str::random(8);
            $dateTime = now()->format('Ymd_His');
            $folderName = $randomString . '_' . $dateTime;
            
            foreach ($files as $fileIndex => $file) {
                // Use original filename from request
                $originalName = $file->getClientOriginalName();
                $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                $extension = $file->getClientOriginalExtension();
                $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nameWithoutExt);
                
                // If sanitized name is empty, use a default
                if (empty($sanitizedName)) {
                    $sanitizedName = 'document_' . $fileIndex;
                }
                
                // Create unique filename: original_name_timestamp_random.ext
                $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                
                // Store in public folder: public/uploads/project/{project_id}/steps/{step_id}/filename
                $stepId = $request->step_id;
                $publicPath = "uploads/project/{$project->id}/steps/{$stepId}";
                $fullPath = public_path($publicPath);
                
                // Create directory if it doesn't exist
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
                
                // Move file to public folder
                $file->move($fullPath, $fileName);
                
                // Generate public URL
                $appUrl = rtrim(config('app.url'), '/');
                $fileUrl = $appUrl . '/' . $publicPath . '/' . $fileName;
                $filePath = $publicPath . '/' . $fileName;
                
                $uploadedDocuments[] = [
                    'name' => $originalName, // Original filename from request
                    'file_name' => $fileName, // Stored filename
                    'file_path' => $filePath,
                    'file_url' => $fileUrl,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }
        }

        $step = ProjectStep::create([
            'project_id' => $project->id,
            'step_id' => $request->step_id,
            'description' => !empty($request->description) ? $request->description : null,
            'status' => $request->status ?? 'pending',
            'completion_date' => $request->completion_date,
            'documents' => $uploadedDocuments, // Keep for backward compatibility
        ]);

        // Save documents to project_step_documents table
        $this->saveStepDocuments($step, $uploadedDocuments, []);

        return response()->json([
            'success' => true,
            'message' => 'Project step added successfully',
            'data' => $step->load('stepDocuments')
        ], 201);
    }

    /**
     * Update a project step.
     */
    public function updateStep(Request $request, $projectId, $stepId)
    {
        $step = ProjectStep::where('project_id', $projectId)->find($stepId);

        if (!$step) {
            return response()->json([
                'success' => false,
                'message' => 'Project step not found'
            ], 404);
        }

        // Custom validation for documents - can be files or array
        $validator = Validator::make($request->all(), [
            'step_id' => 'nullable|integer|min:2|max:5',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'completion_date' => 'nullable|date',
            'existing_documents' => 'nullable|array', // To keep existing documents
        ]);

        // Validate documents if provided as files
        if ($request->hasFile('documents')) {
            $fileValidator = Validator::make($request->all(), [
                'documents.*' => 'file|mimes:pdf,png,jpg,jpeg,gif,webp|max:10240', // Max 10MB - Only PDF, PNG, and images
            ]);
            if ($fileValidator->fails()) {
                $validator->errors()->merge($fileValidator->errors());
            }
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle existing documents from request
        $existingDocs = [];
        if ($request->has('existing_documents')) {
            $existingDocsInput = $request->input('existing_documents', []);
            
            if (is_array($existingDocsInput) && !empty($existingDocsInput)) {
                foreach ($existingDocsInput as $doc) {
                    if (is_array($doc) && isset($doc['file_path']) && !empty($doc['file_path'])) {
                        $existingDocs[] = [
                                    'name' => $doc['name'] ?? '',
                            'file_name' => $doc['file_name'] ?? $doc['name'] ?? '',
                                    'file_path' => $doc['file_path'] ?? '',
                                    'file_url' => $doc['file_url'] ?? '',
                                    'file_size' => $doc['file_size'] ?? 0,
                            'mime_type' => $doc['mime_type'] ?? null,
                                ];
                    }
                }
            }
        } else {
            // Get existing documents from database table
            $existingDocsFromDb = ProjectStepDocument::where('project_step_id', $step->id)->get();
            foreach ($existingDocsFromDb as $doc) {
                // Generate correct URL for authenticated access
                $appUrl = rtrim(config('app.url'), '/');
                $fileUrl = $appUrl . '/api/projects/documents/' . $doc->file_name;
                
                $existingDocs[] = [
                    'name' => $doc->name,
                    'file_name' => $doc->file_name,
                    'file_path' => $doc->file_path,
                    'file_url' => $fileUrl,
                    'file_size' => $doc->file_size,
                    'mime_type' => $doc->mime_type,
                ];
            }
        }

        // Handle new file uploads
        $uploadedDocuments = [];
        if ($request->hasFile('documents')) {
            $files = $request->file('documents');
            
            // Generate folder name: random alphabets + date_time
            $randomString = Str::random(8);
            $dateTime = now()->format('Ymd_His');
            $folderName = $randomString . '_' . $dateTime;
            
            foreach ($files as $fileIndex => $file) {
                // Use original filename from request
                $originalName = $file->getClientOriginalName();
                $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                $extension = $file->getClientOriginalExtension();
                $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nameWithoutExt);
                
                // If sanitized name is empty, use a default
                if (empty($sanitizedName)) {
                    $sanitizedName = 'document_' . $fileIndex;
                }
                
                // Create unique filename: original_name_timestamp_random.ext
                $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                
                // Store in public folder: public/uploads/project/{project_id}/steps/{step_id}/filename
                $stepId = $step->step_id;
                $publicPath = "uploads/project/{$project->id}/steps/{$stepId}";
                $fullPath = public_path($publicPath);
                
                // Create directory if it doesn't exist
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
                
                // Move file to public folder
                $file->move($fullPath, $fileName);
                
                // Generate public URL
                $appUrl = rtrim(config('app.url'), '/');
                $fileUrl = $appUrl . '/' . $publicPath . '/' . $fileName;
                $filePath = $publicPath . '/' . $fileName;
                
                $uploadedDocuments[] = [
                    'name' => $originalName, // Original filename from request
                    'file_name' => $fileName, // Stored filename
                    'file_path' => $filePath,
                    'file_url' => $fileUrl,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }
        }

        // Combine for JSON field (backward compatibility)
        $allDocuments = array_merge($existingDocs, $uploadedDocuments);

        $updateData = [
            'description' => !empty($request->description) ? $request->description : ($step->description ?? null),
            'status' => $request->status ?? $step->status,
            'completion_date' => $request->completion_date,
            'documents' => $allDocuments, // Keep for backward compatibility
        ];

        if ($request->has('step_id')) {
            $updateData['step_id'] = $request->step_id;
        }

        $step->update($updateData);
        
        // Save documents to project_step_documents table
        $this->saveStepDocuments($step, $uploadedDocuments, $existingDocs);

        return response()->json([
            'success' => true,
            'message' => 'Project step updated successfully',
            'data' => $step->fresh(['stepDocuments'])
        ]);
    }

    /**
     * Delete a project step.
     */
    public function deleteStep($projectId, $stepId)
    {
        $step = ProjectStep::where('project_id', $projectId)->find($stepId);

        if (!$step) {
            return response()->json([
                'success' => false,
                'message' => 'Project step not found'
            ], 404);
        }

        // Delete documents from project_step_documents table and files
        $stepDocuments = ProjectStepDocument::where('project_step_id', $step->id)->get();
        
        foreach ($stepDocuments as $doc) {
            // Delete the file from filesystem
            $fullFilePath = public_path($doc->file_path);
                    if (file_exists($fullFilePath)) {
                        unlink($fullFilePath);
                    }

                    // Try to delete the folder if it's empty
            $folderPath = dirname($doc->file_path);
            $fullFolderPath = public_path($folderPath);
                    if (is_dir($fullFolderPath)) {
                        $files = glob($fullFolderPath . '/*');
                        if (empty($files)) {
                            rmdir($fullFolderPath);
                }
            }
        }
        
        // Delete all documents from table
        ProjectStepDocument::where('project_step_id', $step->id)->delete();

        $step->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project step deleted successfully'
        ]);
    }

    /**
     * Recursively extract files from array structure for a specific step_id.
     */
    private function extractFilesFromArray($data, $stepId, &$files)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Check if this is the documents array for our step
                if ($key === 'documents' && isset($data['step_id']) && $data['step_id'] == $stepId) {
                    if (is_array($value)) {
                        foreach ($value as $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $files[] = $file;
                            }
                        }
                    } elseif ($value instanceof \Illuminate\Http\UploadedFile) {
                        $files[] = $value;
                    }
                }
                // Also check nested structure like steps[2][documents]
                if (is_array($value) && ($key == $stepId || (isset($data['step_id']) && $data['step_id'] == $stepId))) {
                    $this->extractFilesFromArray($value, $stepId, $files);
                }
            }
        }
    }

    /**
     * Save step documents to project_step_documents table.
     */
    private function saveStepDocuments(ProjectStep $step, array $uploadedDocuments, array $existingDocs = [])
    {
        // Delete existing documents from table (we'll recreate them)
        ProjectStepDocument::where('project_step_id', $step->id)->delete();
        
        $appUrl = rtrim(config('app.url'), '/');
        
        // Save uploaded documents
        foreach ($uploadedDocuments as $doc) {
            if (is_array($doc) && isset($doc['file_path']) && !empty($doc['file_path'])) {
                // Ensure file_name is set
                $fileName = $doc['file_name'] ?? basename($doc['file_path']);
                // Generate correct URL for authenticated access
                $fileUrl = $appUrl . '/api/projects/documents/' . $fileName;
                
                ProjectStepDocument::create([
                    'project_id' => $step->project_id,
                    'project_step_id' => $step->id,
                    'name' => $doc['name'] ?? '',
                    'file_name' => $fileName,
                    'file_path' => $doc['file_path'] ?? '',
                    'file_url' => $fileUrl,
                    'file_size' => $doc['file_size'] ?? 0,
                    'mime_type' => $doc['mime_type'] ?? null,
                ]);
            }
        }
        
        // Save existing documents (from database)
        foreach ($existingDocs as $doc) {
            if (is_array($doc) && isset($doc['file_path']) && !empty($doc['file_path'])) {
                // Ensure file_name is set
                $fileName = $doc['file_name'] ?? basename($doc['file_path']);
                // Generate correct URL for authenticated access
                $fileUrl = $appUrl . '/api/projects/documents/' . $fileName;
                
                ProjectStepDocument::create([
                    'project_id' => $step->project_id,
                    'project_step_id' => $step->id,
                    'name' => $doc['name'] ?? '',
                    'file_name' => $fileName,
                    'file_path' => $doc['file_path'] ?? '',
                    'file_url' => $fileUrl,
                    'file_size' => $doc['file_size'] ?? 0,
                    'mime_type' => $doc['mime_type'] ?? null,
                ]);
            }
        }
    }

    /**
     * Get projects assigned to the authenticated customer.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerProjects(Request $request)
    {
        $customer = auth('customer-api')->user();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login again.'
            ], 401);
        }

        $search = $request->get('search');

        $query = Project::with(['subscription', 'steps.stepDocuments'])
            ->where('customer_id', $customer->id);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('subscription', function ($subQuery) use ($search) {
                      $subQuery->where('title', 'like', "%{$search}%");
                  });
            });
        }

        $projects = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $projects
        ]);
    }

    /**
     * Get a single project assigned to the authenticated customer.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerProjectShow($id)
    {
        $customer = auth('customer-api')->user();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login again.'
            ], 401);
        }

        // First verify the project exists and is assigned to this customer
        $project = Project::where('customer_id', $customer->id)
            ->find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found or you do not have access to this project.'
            ], 404);
        }

        // Load relationships only if project is verified
        // Eager load steps with their documents
        $project->load([
            'subscription',
            'steps' => function ($query) {
                $query->orderBy('step_id', 'asc');
            },
            'steps.stepDocuments' => function ($query) {
                $query->orderBy('created_at', 'asc');
            }
        ]);

        // Ensure stepDocuments are properly included in the response
        // The relationship name is 'stepDocuments' but Laravel might serialize it as 'step_documents'
        // We'll ensure both are available for frontend compatibility
        $projectData = $project->toArray();
        
        // Transform steps to ensure stepDocuments are included
        if (isset($projectData['steps'])) {
            foreach ($projectData['steps'] as &$step) {
                // Ensure step_documents is present (for API compatibility)
                if (isset($step['step_documents'])) {
                    $step['stepDocuments'] = $step['step_documents'];
                } elseif (isset($step['stepDocuments'])) {
                    $step['step_documents'] = $step['stepDocuments'];
                }
            }
            unset($step); // Break reference
        }

        // Calculate project progress
        // Total steps = 5 (Step 1: Project Information + Steps 2-5: Project Steps)
        $totalSteps = 5;
        $completedSteps = 1; // Step 1 is always considered completed if project exists
        
        // Count completed steps from project_steps (steps 2-5)
        if ($project->steps && $project->steps->count() > 0) {
            $completedProjectSteps = $project->steps->where('status', 'completed')->count();
            $completedSteps += $completedProjectSteps;
        }
        
        $progress = round(($completedSteps / $totalSteps) * 100);
        
        // Add progress to project data
        $projectData['progress'] = $progress;
        $projectData['completed_steps'] = $completedSteps;
        $projectData['total_steps'] = $totalSteps;

        return response()->json([
            'success' => true,
            'data' => $projectData
        ]);
    }

    /**
     * View/download a project step document.
     * Only accessible by authenticated users (admin or customer assigned to the project).
     *
     * @param  string  $fileName
     * @return \Illuminate\Http\Response
     */
    public function viewDocument($fileName)
    {
        // Find the document by file name
        $document = ProjectStepDocument::where('file_name', $fileName)->first();

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found'
            ], 404);
        }

        // Check if user is authenticated (either admin or customer)
        $admin = auth('api')->user();
        $customer = auth('customer-api')->user();

        if (!$admin && !$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login to access this document.'
            ], 401);
        }

        // Verify access: Admin can access all, Customer can only access their assigned projects
        if ($customer) {
            $project = Project::find($document->project_id);
            if (!$project || $project->customer_id != $customer->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this document.'
                ], 403);
            }
        }

        // Get file path - files are stored in public/uploads/project/...
        $filePath = $document->file_path;
        
        // Remove 'public/' prefix if present (public folder is web root)
        if (strpos($filePath, 'public/') === 0) {
            $filePath = substr($filePath, 7);
        }
        
        // Full path to file in public directory
        $fullPath = public_path($filePath);
        
        // Check if file exists
        if (!file_exists($fullPath)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found on server'
            ], 404);
        }

        // Get MIME type
        $mimeType = $document->mime_type ?? mime_content_type($fullPath);
        
        // If MIME type is not available, try to detect from extension
        if (!$mimeType) {
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
            ];
            $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
        }

        // Get file content
        $fileContent = file_get_contents($fullPath);
        
        // Sanitize filename for Content-Disposition header
        $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $document->name);

        // Return file response with inline disposition (view, not download)
        // This allows PDFs and images to be displayed in browser
        return response($fileContent, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . $safeFileName . '"')
            ->header('Content-Length', filesize($fullPath))
            ->header('Cache-Control', 'private, max-age=3600')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

