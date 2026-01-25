<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectStep;
use App\Models\ProjectStepDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AssignProjectController extends Controller
{
    /**
     * Create a new project with steps
     * POST /api/assign-projects
     */
    public function store(Request $request)
    {
        // Parse FormData - handle underscore format
        $allData = $this->parseFormData($request);
        
        // Validate project data (Step 1 data)
        $validator = Validator::make($allData, [
            'name' => 'required|string|max:255',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'customer_id' => 'nullable|exists:customers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Create project (Step 1 data)
        $project = Project::create([
            'name' => $allData['name'],
            'subscription_id' => $allData['subscription_id'] ?? null,
            'customer_id' => $allData['customer_id'] ?? null,
            'start_date' => $allData['start_date'],
            'end_date' => $allData['end_date'],
            'description' => $allData['description'] ?? null,
        ]);

        // Process steps 2-5 if provided
        if (isset($allData['steps']) && is_array($allData['steps'])) {
            $this->processSteps($project, $allData['steps'], $request);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project assigned successfully',
            'data' => $project->fresh(['subscription', 'customer', 'steps.stepDocuments'])
        ], 201);
    }

    /**
     * Update an existing project with steps
     * POST /api/assign-projects/{id}
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

        // Parse FormData - handle underscore format
        $allData = $this->parseFormData($request);

        // Validate project data (Step 1 data)
        $validator = Validator::make($allData, [
            'name' => 'required|string|max:255',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'customer_id' => 'nullable|exists:customers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update project (Step 1 data)
        $project->update([
            'name' => $allData['name'],
            'subscription_id' => $allData['subscription_id'] ?? null,
            'customer_id' => $allData['customer_id'] ?? null,
            'start_date' => $allData['start_date'],
            'end_date' => $allData['end_date'],
            'description' => $allData['description'] ?? null,
        ]);

        // Process steps 2-5 if provided
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
     * Parse FormData with underscore format
     */
    private function parseFormData(Request $request)
    {
        $allData = [];
        
        // Get all data from request
        $requestData = $request->all();
        
        // Parse simple fields (Step 1 - Project data)
        $simpleFields = ['name', 'subscription_id', 'customer_id', 'start_date', 'end_date', 'description'];
        foreach ($simpleFields as $field) {
            $value = $request->input($field);
            if ($value !== null && $value !== '') {
                $allData[$field] = $value;
            }
        }
        
        // Parse steps array from underscore format: steps_2_step_id, steps_2_description, etc.
        $steps = [];
        $allKeys = array_keys($requestData);
        
        foreach ($allKeys as $key) {
            // Match underscore format: steps_2_step_id, steps_2_description, steps_2_documents_0, etc.
            if (preg_match('/^steps_(\d+)_(.+)$/', $key, $matches)) {
                $stepIndex = $matches[1];
                $fieldName = $matches[2];
                
                if (!isset($steps[$stepIndex])) {
                    $steps[$stepIndex] = [];
                }
                
                $value = $request->input($key);
                
                // Handle documents: steps_2_documents_0 (files will be handled separately)
                if (preg_match('/^documents_(\d+)$/', $fieldName, $docMatches)) {
                    // Skip files here - they'll be handled in processSteps
                    continue;
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
        
        return $allData;
    }

    /**
     * Process steps 2-5 for a project
     */
    private function processSteps(Project $project, array $steps, Request $request)
    {
        // Filter out null values
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
                continue;
            }

            // Get files directly from request using underscore format
            $uploadedDocuments = [];
            $files = [];
            
            // Get files directly: steps_2_documents_0, steps_2_documents_1, etc.
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
                
                // Also check allFiles() directly
                if (empty($files)) {
                    $allRequestFiles = $request->allFiles();
                    foreach ($allRequestFiles as $key => $file) {
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
            
            // Filter out non-file objects
            $files = array_filter($files, function($file) {
                return $file instanceof \Illuminate\Http\UploadedFile;
            });
            
            // Process and store uploaded files directly
            if (!empty($files)) {
                foreach ($files as $fileIndex => $file) {
                    // Validate file type
                    $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
                    $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
                    
                    $mimeType = $file->getMimeType();
                    $extension = strtolower($file->getClientOriginalExtension());
                    
                    if (!in_array($mimeType, $allowedMimes) && !in_array($extension, $allowedExtensions)) {
                        continue;
                    }
                    
                    // Use original filename from request
                    $originalName = $file->getClientOriginalName();
                    $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
                    $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nameWithoutExt);
                    
                    if (empty($sanitizedName)) {
                        $sanitizedName = 'document_' . $fileIndex;
                    }
                    
                    // Create unique filename
                    $fileName = $sanitizedName . '_' . time() . '_' . Str::random(4) . '.' . $extension;
                    
                    // Store in public folder: public/uploads/project/{project_id}/steps/{step_id}/filename
                    $publicPath = "uploads/project/{$project->id}/steps/{$stepId}";
                    $fullPath = public_path($publicPath);
                    
                    // Create directory if it doesn't exist
                    if (!file_exists($fullPath)) {
                        mkdir($fullPath, 0755, true);
                    }
                    
                    // Get file size and mime type BEFORE moving the file
                    $fileSize = $file->getSize();
                    $mimeType = $file->getMimeType();
                    
                    // Move file to public folder
                    $file->move($fullPath, $fileName);
                    
                    // Generate public URL
                    $appUrl = rtrim(config('app.url'), '/');
                    $fileUrl = $appUrl . '/' . $publicPath . '/' . $fileName;
                    $filePath = $publicPath . '/' . $fileName;
                    
                    $uploadedDocuments[] = [
                        'name' => $originalName,
                        'file_name' => $fileName,
                        'file_path' => $filePath,
                        'file_url' => $fileUrl,
                        'file_size' => $fileSize,
                        'mime_type' => $mimeType,
                    ];
                }
            }

            // Find or create step FIRST (before saving documents)
            $step = ProjectStep::firstOrCreate(
                [
                    'project_id' => $project->id,
                    'step_id' => $stepId,
                ],
                [
                    'description' => !empty($stepData['description']) ? $stepData['description'] : null,
                    'status' => $stepData['status'] ?? 'pending',
                    'completion_date' => $stepData['completion_date'] ?? null,
                ]
            );
            
            // Update step if it already existed
            if ($step->wasRecentlyCreated === false) {
                $step->update([
                    'description' => !empty($stepData['description']) ? $stepData['description'] : ($step->description ?? null),
                    'status' => $stepData['status'] ?? $step->status ?? 'pending',
                    'completion_date' => $stepData['completion_date'] ?? null,
                ]);
            }
            
            // Save documents to database DIRECTLY (immediately after step is created)
            if (!empty($uploadedDocuments)) {
                foreach ($uploadedDocuments as $doc) {
                    ProjectStepDocument::create([
                        'project_id' => $project->id,
                        'project_step_id' => $step->id,
                        'name' => $doc['name'],
                        'file_name' => $doc['file_name'],
                        'file_path' => $doc['file_path'],
                        'file_url' => $doc['file_url'],
                        'file_size' => $doc['file_size'],
                        'mime_type' => $doc['mime_type'],
                    ]);
                }
            }
            
            // Handle deleted documents first
            $deletedDocs = [];
            if ($request) {
                for ($i = 0; $i < 10; $i++) {
                    $deleteKey = "steps_{$stepId}_deleted_documents_{$i}_file_path";
                    $deleteFlag = "steps_{$stepId}_deleted_documents_{$i}_delete";
                    
                    if ($request->has($deleteKey) && $request->input($deleteFlag) === 'true') {
                        $filePath = $request->input($deleteKey);
                        if (!empty($filePath)) {
                            $deletedDocs[] = $filePath;
                        }
                    } else {
                        if ($i > 0 && !empty($deletedDocs)) {
                            break;
                        }
                    }
                }
            }
            
            // Delete documents and files
            if (!empty($deletedDocs)) {
                foreach ($deletedDocs as $filePath) {
                    // Find document in database
                    $document = ProjectStepDocument::where('project_step_id', $step->id)
                        ->where('file_path', $filePath)
                        ->first();
                    
                    if ($document) {
                        // Delete physical file if it exists
                        $fullFilePath = public_path($filePath);
                        if (file_exists($fullFilePath)) {
                            @unlink($fullFilePath);
                        }
                        
                        // Delete database record
                        $document->delete();
                    }
                }
            }
            
            // Handle existing documents (to keep them)
            if (isset($stepData['existing_documents']) && is_array($stepData['existing_documents'])) {
                foreach ($stepData['existing_documents'] as $doc) {
                    if (is_array($doc) && !empty($doc['file_path'])) {
                        ProjectStepDocument::updateOrCreate(
                            [
                                'project_step_id' => $step->id,
                                'file_path' => $doc['file_path'],
                            ],
                            [
                                'project_id' => $project->id,
                                'name' => $doc['name'] ?? '',
                                'file_name' => $doc['file_name'] ?? '',
                                'file_url' => $doc['file_url'] ?? '',
                                'file_size' => $doc['file_size'] ?? 0,
                                'mime_type' => $doc['mime_type'] ?? '',
                            ]
                        );
                    }
                }
            }
        }
    }

}

