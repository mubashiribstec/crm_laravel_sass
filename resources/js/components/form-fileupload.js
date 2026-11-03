/*
Template Name: Lahomes - Real Estate Admin Dashboard Template
Author: Techzaa
File: form - Dropzone js
*/
import Dropzone from 'dropzone/dist/dropzone';

Dropzone.autoDiscover = false;

document.addEventListener("DOMContentLoaded", function () {
    const templateNode = document.querySelector("#dz-preview-template");
    if (!templateNode) return;

    const previewTemplate = templateNode.innerHTML;

    // Helper to generate dynamic file icons based on extension
    function handleAddedFile(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        const thumbnailContainer = file.previewElement.querySelector("[data-dz-thumbnail]");

        if (!thumbnailContainer) return;

        let icon = "solar:file-bold";   // default icon
        let colorClass = "text-secondary"; // default color

        if (ext === "pdf") {
            icon = "solar:file-text-bold";
            colorClass = "text-danger"; // red
        } 
        else if (ext === "doc" || ext === "docx") {
            icon = "solar:document-bold";
            colorClass = "text-primary"; // blue
        } 
        else if (ext === "csv" || ext === "xls" || ext === "xlsx") {
            icon = "solar:table-bold";
            colorClass = "text-success"; // green
        }

        // Inject icon with color dynamically
        thumbnailContainer.innerHTML = `<iconify-icon icon="${icon}" class="fs-32 ${colorClass}"></iconify-icon>`;
    }

    function initializeDropzone(elementId, previewId, options = {}) {
        const element = document.querySelector(`#${elementId}`);
        if (!element) return;

        new Dropzone(element, {
            url: "/",
            method: "post",
            autoProcessQueue: false,
            previewsContainer: `#${previewId}`,
            previewTemplate: previewTemplate,
            maxFilesize: 10,
            acceptedFiles: options.acceptedFiles,
            maxFiles: options.maxFiles,
            clickable: true,
            init: function () {
                this.on("addedfile", handleAddedFile);
                if (options.singleFile) {
                    this.on("maxfilesexceeded", function (file) {
                        this.removeAllFiles();
                        this.addFile(file);
                    });
                }
            }
        });
    }

    initializeDropzone("applicantCvDropzone", "dropzone-preview", {
        acceptedFiles: ".pdf,.doc,.docx,.txt",
        maxFiles: 1,
        singleFile: true
    });
    initializeDropzone("salesAttachmentDropzone", "dropzone-preview", {
        acceptedFiles: ".pdf,.doc,.docx,.txt",
        maxFiles: 20
    });
    initializeDropzone("officeAttachmentDropzone", "officeAttachmentPreview");
    initializeDropzone("unitAttachmentDropzone", "unitAttachmentPreview");
});
