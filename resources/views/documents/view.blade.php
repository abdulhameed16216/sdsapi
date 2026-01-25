<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Document - {{ $fileName }}</title>
    <!-- PDF.js library for rendering PDFs -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: rgb(103, 122, 88);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #6D7659 0%, #BDC6B5 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }
        
        .document-info {
            background: #f8f9fa;
            padding: 15px 30px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .document-name {
            font-size: 16px;
            font-weight: 500;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .document-meta {
            display: flex;
            gap: 20px;
            font-size: 14px;
            color: #666;
        }
        
        .document-viewer {
            padding: 30px;
            background: #f8f9fa;
            min-height: 600px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        
        .document-viewer img {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            pointer-events: none;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            -webkit-user-drag: none;
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
        }
        
        .document-viewer iframe {
            width: 100%;
            min-height: 80vh;
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            pointer-events: auto;
            user-select: none;
            -webkit-user-select: none;
        }
        
        /* Overlay to prevent direct interaction with PDF */
        .pdf-container {
            position: relative;
        }
        
        .pdf-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1;
            pointer-events: none;
        }
        
        /* Prevent text selection */
        body {
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        
        /* Allow text selection only for header and info sections */
        .header, .document-info {
            -webkit-user-select: text;
            -moz-user-select: text;
            -ms-user-select: text;
            user-select: text;
        }
        
        .document-viewer .pdf-container {
            width: 100%;
            min-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .loading {
            text-align: center;
            color: #666;
            font-size: 16px;
        }
        
        .error-message {
            text-align: center;
            padding: 40px;
            color: #dc3545;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .document-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .document-viewer {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">        
        <!-- Document Viewer -->
        <div class="document-viewer">
            @if($isImage)
                <img src="{{ $fileUrl }}" alt="{{ $fileName }}" onerror="this.parentElement.innerHTML='<div class=\'error-message\'>Failed to load image</div>'">
            @elseif($isPdf)
                <div class="pdf-container">
                    <canvas id="pdf-canvas" style="width: 100%; max-width: 100%; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);"></canvas>
                    <div id="pdf-controls" style="text-align: center; margin-top: 15px; user-select: none;">
                        <button id="prev-page" style="padding: 8px 16px; margin: 0 5px; background: #6D7659; color: white; border: none; border-radius: 4px; cursor: pointer;">Previous</button>
                        <span id="page-info" style="margin: 0 15px; color: #666;">Page <span id="page-num">1</span> of <span id="page-count">1</span></span>
                        <button id="next-page" style="padding: 8px 16px; margin: 0 5px; background: #6D7659; color: white; border: none; border-radius: 4px; cursor: pointer;">Next</button>
                    </div>
                </div>
            @else
                <div class="error-message">
                    <p>Unsupported file type. <a href="{{ $fileUrl }}" target="_blank">Click here to download</a></p>
                </div>
            @endif
        </div>
    </div>
    
    <script>
        // Prevent right-click, context menu, and common keyboard shortcuts
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });

        // Prevent text selection
        document.addEventListener('selectstart', function(e) {
            e.preventDefault();
            return false;
        });

        // Prevent drag and drop
        document.addEventListener('dragstart', function(e) {
            e.preventDefault();
            return false;
        });

        // Disable common keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Disable Ctrl+S (Save), Ctrl+A (Select All), Ctrl+C (Copy), Ctrl+P (Print), F12 (DevTools)
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'a' || e.key === 'A' || e.key === 'c' || e.key === 'C' || e.key === 'p' || e.key === 'P')) {
                e.preventDefault();
                return false;
            }
            // Disable F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U
            if (e.key === 'F12' || 
                ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'I' || e.key === 'J')) ||
                ((e.ctrlKey || e.metaKey) && e.key === 'u' || e.key === 'U')) {
                e.preventDefault();
                return false;
            }
        });

        // Prevent image right-click and drag
        document.addEventListener('DOMContentLoaded', function() {
            const img = document.querySelector('img');
            if (img) {
                img.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    return false;
                });
                img.addEventListener('dragstart', function(e) {
                    e.preventDefault();
                    return false;
                });
                // Add overlay to prevent direct image access
                img.style.pointerEvents = 'none';
                img.style.userSelect = 'none';
                img.style.webkitUserSelect = 'none';
                img.style.mozUserSelect = 'none';
                img.style.msUserSelect = 'none';
            }

            // Handle PDF rendering with PDF.js
            const pdfCanvas = document.getElementById('pdf-canvas');
            if (pdfCanvas) {
                let pdfDoc = null;
                let pageNum = 1;
                let pageRendering = false;
                let pageNumPending = null;
                const scale = 1.5;
                const ctx = pdfCanvas.getContext('2d');

                // Set PDF.js worker
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

                function renderPage(num) {
                    pageRendering = true;
                    pdfDoc.getPage(num).then(function(page) {
                        const viewport = page.getViewport({scale: scale});
                        pdfCanvas.height = viewport.height;
                        pdfCanvas.width = viewport.width;

                        const renderContext = {
                            canvasContext: ctx,
                            viewport: viewport
                        };
                        const renderTask = page.render(renderContext);

                        renderTask.promise.then(function() {
                            pageRendering = false;
                            if (pageNumPending !== null) {
                                renderPage(pageNumPending);
                                pageNumPending = null;
                            }
                        });
                    });

                    document.getElementById('page-num').textContent = num;
                }

                function queueRenderPage(num) {
                    if (pageRendering) {
                        pageNumPending = num;
                    } else {
                        renderPage(num);
                    }
                }

                function onPrevPage() {
                    if (pageNum <= 1) return;
                    pageNum--;
                    queueRenderPage(pageNum);
                }

                function onNextPage() {
                    if (pageNum >= pdfDoc.numPages) return;
                    pageNum++;
                    queueRenderPage(pageNum);
                }

                document.getElementById('prev-page').addEventListener('click', onPrevPage);
                document.getElementById('next-page').addEventListener('click', onNextPage);

                // Load PDF
                pdfjsLib.getDocument('{{ $fileUrl }}').promise.then(function(pdf) {
                    pdfDoc = pdf;
                    document.getElementById('page-count').textContent = pdf.numPages;
                    renderPage(pageNum);
                }).catch(function(error) {
                    pdfCanvas.parentElement.innerHTML = '<div class="error-message"><p>Failed to load PDF: ' + error.message + '. <a href="{{ $fileUrl }}" target="_blank">Click here to open in new tab</a></p></div>';
                });
            }

            // Add overlay protection
            const viewer = document.querySelector('.document-viewer');
            if (viewer) {
                viewer.style.position = 'relative';
                viewer.style.userSelect = 'none';
                viewer.style.webkitUserSelect = 'none';
            }
        });

        // Disable print
        window.addEventListener('beforeprint', function(e) {
            e.preventDefault();
            return false;
        });
    </script>
</body>
</html>

