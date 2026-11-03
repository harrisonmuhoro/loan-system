// Extended Loan Application Form Handler with Image Annotation
// Place this in your loanee.php or in a separate JS file

(function() {
    'use strict';
    
    // Global variables for annotation
    let currentCanvas = null;
    let currentContext = null;
    let currentImage = null;
    let currentFieldName = null;
    let isDrawing = false;
    let currentTool = 'pen';
    let currentColor = '#ff0000';
    let startX, startY;
    let annotations = [];
    
    // Initialize form on page load
    document.addEventListener('DOMContentLoaded', function() {
        initializeForm();
        initializeLoanTypeToggle();
        initializeFileUploads();
        initializeAnnotationTools();
    });
    
    /* =================================================================
     * FORM INITIALIZATION
     * ================================================================= */
    function initializeForm() {
        const form = document.getElementById('extendedLoanForm');
        if (!form) return;
        
        // Generate application ID
        const appId = 'APP' + Date.now() + Math.floor(Math.random() * 10000);
        document.getElementById('application_id').value = appId;
        
        // Handle form submission
        form.addEventListener('submit', handleFormSubmit);
        
        // Auto-fill email and names from session if available
        autoFillUserData();
    }
    
    function autoFillUserData() {
        // These should be available from PHP session
        const userEmail = '<?php echo $_SESSION["email"] ?? ""; ?>';
        const userFullName = '<?php echo $_SESSION["full_name"] ?? ""; ?>';
        const userPfNumber = '<?php echo $_SESSION["pf_number"] ?? ""; ?>';
        const userIdNumber = '<?php echo $_SESSION["id_number"] ?? ""; ?>';
        
        if (userEmail) document.getElementById('email_address').value = userEmail;
        if (userPfNumber) document.getElementById('pf_number').value = userPfNumber;
        if (userIdNumber) document.getElementById('id_number').value = userIdNumber;
        
        // Split full name into surname and other names
        if (userFullName) {
            const nameParts = userFullName.trim().split(' ');
            if (nameParts.length > 0) {
                document.getElementById('surname').value = nameParts[nameParts.length - 1];
                document.getElementById('other_names').value = nameParts.slice(0, -1).join(' ');
            }
        }
    }
    
    /* =================================================================
     * LOAN TYPE TOGGLE (Show/Hide Mortgage & Car Fields)
     * ================================================================= */
    function initializeLoanTypeToggle() {
        const loanTypeSelect = document.getElementById('loan_type');
        if (!loanTypeSelect) return;
        
        loanTypeSelect.addEventListener('change', function() {
            const mortgageFields = document.getElementById('mortgageFields');
            const carFields = document.getElementById('carFields');
            
            // Hide all conditional fields
            mortgageFields.style.display = 'none';
            carFields.style.display = 'none';
            
            // Show relevant fields
            if (this.value === 'mortgage') {
                mortgageFields.style.display = 'block';
            } else if (this.value === 'car') {
                carFields.style.display = 'block';
            }
        });
    }
    
    /* =================================================================
     * FILE UPLOAD HANDLERS
     * ================================================================= */
    function initializeFileUploads() {
        const fileInputs = ['id_front_image', 'id_back_image', 'payslip_image', 'kra_pin_image'];
        
        fileInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            const uploadArea = input.closest('.upload-area');
            
            if (!input || !uploadArea) return;
            
            // Handle file selection
            input.addEventListener('change', function(e) {
                handleFileSelect(e.target, uploadArea);
            });
            
            // Drag and drop
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = '#1976d2';
                this.style.background = '#f5f9ff';
            });
            
            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.style.borderColor = '#ddd';
                this.style.background = 'transparent';
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = '#ddd';
                this.style.background = 'transparent';
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    input.files = files;
                    handleFileSelect(input, uploadArea);
                }
            });
        });
    }
    
    function handleFileSelect(input, uploadArea) {
        const file = input.files[0];
        if (!file) return;
        
        // Validate file type
        if (!file.type.startsWith('image/')) {
            alert('Please select an image file');
            input.value = '';
            return;
        }
        
        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB');
            input.value = '';
            return;
        }
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
            const placeholder = uploadArea.querySelector('.upload-placeholder');
            const previewContainer = uploadArea.querySelector('.preview-container');
            const previewImage = previewContainer.querySelector('.preview-image');
            
            previewImage.src = e.target.result;
            placeholder.style.display = 'none';
            previewContainer.style.display = 'block';
            
            // Setup remove button
            const removeBtn = previewContainer.querySelector('.remove-image');
            removeBtn.onclick = function() {
                input.value = '';
                placeholder.style.display = 'block';
                previewContainer.style.display = 'none';
            };
            
            // Setup annotate button
            const annotateBtn = previewContainer.querySelector('.annotate-image');
            annotateBtn.onclick = function() {
                openAnnotationModal(e.target.result, input.id);
            };
        };
        
        reader.readAsDataURL(file);
    }
    
    /* =================================================================
     * IMAGE ANNOTATION
     * ================================================================= */
    function initializeAnnotationTools() {
        const canvas = document.getElementById('annotationCanvas');
        if (!canvas) return;
        
        currentCanvas = canvas;
        currentContext = canvas.getContext('2d');
        
        // Tool selection
        document.querySelectorAll('.tool-btn[data-tool]').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tool-btn[data-tool]').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentTool = this.dataset.tool;
            });
        });
        
        // Color picker
        const colorPicker = document.getElementById('colorPicker');
        if (colorPicker) {
            colorPicker.addEventListener('change', function() {
                currentColor = this.value;
            });
        }
        
        // Canvas drawing events
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);
        
        // Touch events for mobile
        canvas.addEventListener('touchstart', handleTouchStart);
        canvas.addEventListener('touchmove', handleTouchMove);
        canvas.addEventListener('touchend', stopDrawing);
    }
    
    function openAnnotationModal(imageSrc, fieldName) {
        const modal = document.getElementById('annotationModal');
        const canvas = document.getElementById('annotationCanvas');
        
        currentFieldName = fieldName;
        currentImage = new Image();
        currentImage.onload = function() {
            // Set canvas size to match image
            canvas.width = Math.min(currentImage.width, 900);
            canvas.height = (currentImage.height * canvas.width) / currentImage.width;
            
            // Draw image on canvas
            currentContext.drawImage(currentImage, 0, 0, canvas.width, canvas.height);
            
            // Restore previous annotations if any
            if (annotations[fieldName]) {
                annotations[fieldName].forEach(annotation => {
                    drawAnnotation(annotation);
                });
            }
        };
        currentImage.src = imageSrc;
        
        modal.style.display = 'flex';
    }
    
    function closeAnnotationModal() {
        document.getElementById('annotationModal').style.display = 'none';
    }
    
    function startDrawing(e) {
        isDrawing = true;
        const rect = currentCanvas.getBoundingClientRect();
        startX = e.clientX - rect.left;
        startY = e.clientY - rect.top;
        
        if (currentTool === 'text') {
            const text = prompt('Enter text:');
            if (text) {
                currentContext.font = '20px Arial';
                currentContext.fillStyle = currentColor;
                currentContext.fillText(text, startX, startY);
                
                saveAnnotationData({
                    tool: 'text',
                    text: text,
                    x: startX,
                    y: startY,
                    color: currentColor
                });
            }
            isDrawing = false;
        }
    }
    
    function draw(e) {
        if (!isDrawing || currentTool === 'text') return;
        
        const rect = currentCanvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        currentContext.strokeStyle = currentColor;
        currentContext.lineWidth = currentTool === 'highlighter' ? 10 : 2;
        currentContext.globalAlpha = currentTool === 'highlighter' ? 0.3 : 1;
        currentContext.lineCap = 'round';
        
        if (currentTool === 'pen' || currentTool === 'highlighter') {
            currentContext.beginPath();
            currentContext.moveTo(startX, startY);
            currentContext.lineTo(x, y);
            currentContext.stroke();
            
            startX = x;
            startY = y;
        } else if (currentTool === 'arrow') {
            // Redraw image and previous annotations
            redrawCanvas();
            
            // Draw arrow
            drawArrow(startX, startY, x, y);
        }
    }
    
    function stopDrawing(e) {
        if (isDrawing && currentTool === 'arrow') {
            const rect = currentCanvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            saveAnnotationData({
                tool: 'arrow',
                startX: startX,
                startY: startY,
                endX: x,
                endY: y,
                color: currentColor
            });
        }
        
        isDrawing = false;
        currentContext.globalAlpha = 1;
    }
    
    function handleTouchStart(e) {
        e.preventDefault();
        const touch = e.touches[0];
        const mouseEvent = new MouseEvent('mousedown', {
            clientX: touch.clientX,
            clientY: touch.clientY
        });
        currentCanvas.dispatchEvent(mouseEvent);
    }
    
    function handleTouchMove(e) {
        e.preventDefault();
        const touch = e.touches[0];
        const mouseEvent = new MouseEvent('mousemove', {
            clientX: touch.clientX,
            clientY: touch.clientY
        });
        currentCanvas.dispatchEvent(mouseEvent);
    }
    
    function drawArrow(fromX, fromY, toX, toY) {
        const headLength = 15;
        const angle = Math.atan2(toY - fromY, toX - fromX);
        
        currentContext.strokeStyle = currentColor;
        currentContext.lineWidth = 2;
        currentContext.beginPath();
        currentContext.moveTo(fromX, fromY);
        currentContext.lineTo(toX, toY);
        currentContext.stroke();
        
        // Arrow head
        currentContext.beginPath();
        currentContext.moveTo(toX, toY);
        currentContext.lineTo(
            toX - headLength * Math.cos(angle - Math.PI / 6),
            toY - headLength * Math.sin(angle - Math.PI / 6)
        );
        currentContext.moveTo(toX, toY);
        currentContext.lineTo(
            toX - headLength * Math.cos(angle + Math.PI / 6),
            toY - headLength * Math.sin(angle + Math.PI / 6)
        );
        currentContext.stroke();
    }
    
    function drawAnnotation(annotation) {
        currentContext.strokeStyle = annotation.color;
        currentContext.fillStyle = annotation.color;
        
        if (annotation.tool === 'arrow') {
            drawArrow(annotation.startX, annotation.startY, annotation.endX, annotation.endY);
        } else if (annotation.tool === 'text') {
            currentContext.font = '20px Arial';
            currentContext.fillText(annotation.text, annotation.x, annotation.y);
        }
    }
    
    function redrawCanvas() {
        currentContext.clearRect(0, 0, currentCanvas.width, currentCanvas.height);
        currentContext.drawImage(currentImage, 0, 0, currentCanvas.width, currentCanvas.height);
        
        if (annotations[currentFieldName]) {
            annotations[currentFieldName].forEach(annotation => {
                drawAnnotation(annotation);
            });
        }
    }
    
    function saveAnnotationData(annotation) {
        if (!annotations[currentFieldName]) {
            annotations[currentFieldName] = [];
        }
        annotations[currentFieldName].push(annotation);
    }
    
    function clearAnnotations() {
        if (confirm('Clear all annotations?')) {
            annotations[currentFieldName] = [];
            redrawCanvas();
        }
    }
    
    function saveAnnotation() {
        // Convert canvas to blob
        currentCanvas.toBlob(function(blob) {
            // Replace the original file with annotated version
            const file = new File([blob], currentFieldName + '.png', { type: 'image/png' });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            
            document.getElementById(currentFieldName).files = dataTransfer.files;
            
            // Update preview
            const reader = new FileReader();
            reader.onload = function(e) {
                const uploadArea = document.getElementById(currentFieldName).closest('.upload-area');
                const previewImage = uploadArea.querySelector('.preview-image');
                previewImage.src = e.target.result;
            };
            reader.readAsDataURL(blob);
            
            closeAnnotationModal();
        }, 'image/png');
    }
    
    // Make functions globally accessible
    window.closeAnnotationModal = closeAnnotationModal;
    window.clearAnnotations = clearAnnotations;
    window.saveAnnotation = saveAnnotation;
    
    /* =================================================================
     * FORM SUBMISSION
     * ================================================================= */
    async function handleFormSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const submitBtn = document.getElementById('submitBtn');
        const btnText = submitBtn.querySelector('.btn-text');
        const btnLoader = submitBtn.querySelector('.btn-loader');
        
        // Disable submit button
        submitBtn.disabled = true;
        btnText.style.display = 'none';
        btnLoader.style.display = 'flex';
        
        try {
            // Create FormData
            const formData = new FormData(form);
            
            // Add additional required fields for loan_applications table
            formData.set('full_name', formData.get('surname') + ' ' + formData.get('other_names'));
            formData.set('email', formData.get('email_address'));
            formData.set('basic_salary', formData.get('net_salary'));
            
            // Submit to API
            const response = await fetch('../api/loans.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('Application submitted successfully! Application ID: ' + result.application_id);
                form.reset();
                cancelNewApplication();
                
                // Reload loans list
                if (typeof loadLoans === 'function') {
                    loadLoans();
                }
            } else {
                alert('Error: ' + (result.message || 'Failed to submit application'));
            }
            
        } catch (error) {
            console.error('Submission error:', error);
            alert('An error occurred while submitting the application. Please try again.');
        } finally {
            // Re-enable submit button
            submitBtn.disabled = false;
            btnText.style.display = 'inline';
            btnLoader.style.display = 'none';
        }
    }
    
    /* =================================================================
     * UTILITY FUNCTIONS
     * ================================================================= */
    window.cancelNewApplication = function() {
        document.getElementById('newApplicationForm').style.display = 'none';
        document.getElementById('extendedLoanForm').reset();
        
        // Clear all file previews
        document.querySelectorAll('.upload-area').forEach(area => {
            const placeholder = area.querySelector('.upload-placeholder');
            const preview = area.querySelector('.preview-container');
            if (placeholder && preview) {
                placeholder.style.display = 'block';
                preview.style.display = 'none';
            }
        });
        
        // Clear annotations
        annotations = [];
    };
    
})();
