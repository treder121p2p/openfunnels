/**
 * OpenFunnels Image Upload Plugin
 * Adds upload buttons to image URL fields in the editor
 */
(function() {
    'use strict';

    // Wait for DOM to be ready
    function init() {
        // Observer for URL input fields
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) {
                        addUploadButtons(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
        addUploadButtons(document.body);
    }

    function addUploadButtons(root) {
        // Find all URL input fields that are for images
        const urlInputs = root.querySelectorAll('input[type="url"]');
        urlInputs.forEach((input) => {
            if (input.dataset.uploadAdded) return;
            
            const label = input.closest('label');
            if (!label) return;
            
            const labelText = label.querySelector('span')?.textContent || '';
            const isImageField = /image|photo|avatar|poster|src/i.test(labelText) || 
                                input.placeholder?.includes('image') ||
                                input.placeholder?.includes('https://');
            
            if (!isImageField) return;
            
            input.dataset.uploadAdded = 'true';
            
            // Create upload button
            const uploadBtn = document.createElement('button');
            uploadBtn.type = 'button';
            uploadBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Upload';
            uploadBtn.className = 'inline-flex items-center gap-1 rounded border border-gray-300 bg-gray-100 px-2 py-1 text-[11px] text-gray-600 hover:bg-gray-200';
            uploadBtn.title = 'Upload image from computer';
            uploadBtn.style.cssText = 'flex-shrink: 0; margin-left: 4px;';
            
            // Create hidden file input
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/jpeg,image/png,image/gif,image/webp,image/svg+xml';
            fileInput.style.display = 'none';
            
            fileInput.addEventListener('change', async (e) => {
                const file = e.target.files?.[0];
                if (!file) return;
                
                uploadBtn.disabled = true;
                uploadBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="animate-spin"><circle cx="12" cy="12" r="10"/></svg> Uploading...';
                
                try {
                    const formData = new FormData();
                    formData.append('file', file);
                    
                    const res = await fetch('/api/upload', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData,
                        credentials: 'same-origin',
                    });
                    
                    if (!res.ok) throw new Error(`Upload failed: ${res.status}`);
                    
                    const data = await res.json();
                    
                    // Set the URL in the input field
                    const nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                    nativeInputValueSetter.call(input, data.url);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    uploadBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Done';
                    setTimeout(() => {
                        uploadBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Upload';
                    }, 2000);
                } catch (error) {
                    console.error('Upload error:', error);
                    alert('Failed to upload image. Please try again.');
                    uploadBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Upload';
                } finally {
                    uploadBtn.disabled = false;
                    fileInput.value = '';
                }
            });
            
            uploadBtn.addEventListener('click', () => fileInput.click());
            
            // Add button next to input
            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'display: flex; align-items: center; gap: 4px; width: 100%;';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            wrapper.appendChild(fileInput);
            wrapper.appendChild(uploadBtn);
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
