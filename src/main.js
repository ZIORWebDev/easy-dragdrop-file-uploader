import createFilePondInstance from "./dragdrop/main.js";

$(document).ready(function () {
    const filePondInstances = new Map();
    const dragDropUploaderFields = $(".easy-dragdrop-upload");
    let filePondIntegration = EasyDragDropUploader || {};

    filePondIntegration.allowMultiple = EasyDragDropUploader.allowMultiple === "1";

    // Raised event before the FilePond instance is created
    // To allow developers to set global FilePond options
    $(document).trigger("easy_dragdrop_before_instance_created", filePondIntegration);

    dragDropUploaderFields.each(function () {
        const inputConfig = getInputConfiguration($(this)) || {};
        const configuration = { ...filePondIntegration, ...inputConfig };

        for (const key in configuration) {
            if (!inputConfig[key] && filePondIntegration[key]) {
                configuration[key] = filePondIntegration[key];
            }
        }

        const filePondInstance = createFilePondInstance($(this)[0], configuration);

        // Raised event a FilePond instance is created
        $(document).trigger("easy_dragdrop_instance_created", filePondInstance);

        filePondInstances.set(this, filePondInstance);
    });

    // On elementor form success, clear the filepond field
    $(document).on("submit_success", function (event, response) {
        filePondInstances.forEach((instance) => instance.removeFiles());
    });
});

function getInputConfiguration(fileInput) {
    const data = $(fileInput).data();

    return {
        acceptedFileTypes: data.filetypes?.split(",") ?? null,
        allowMultiple: fileInput.attr("multiple") !== undefined,
        labelIdle: data.label ?? "",
        maxFileSize: data.filesize ? `${data.filesize}MB` : null,
        maxFiles: data.maxfiles ?? null
    }
}