import { uploadPresigned } from '@vercel/blob/client';

const root = document.querySelector('[data-vercel-blob-large-upload]');

if (root) {
    const fileInput = root.querySelector('[data-upload-files]');
    const startButton = root.querySelector('[data-upload-start]');
    const cancelButton = root.querySelector('[data-upload-cancel]');
    const message = root.querySelector('[data-upload-message]');
    const list = root.querySelector('[data-upload-list]');
    const limit = root.querySelector('[data-upload-limit]');
    const maximumSize = Number(root.dataset.maximumSize || 0);
    let activeController = null;

    limit.textContent = formatBytes(maximumSize);
    fileInput.addEventListener('change', () => {
        startButton.disabled = fileInput.files.length === 0;
        list.replaceChildren();
        hideMessage();
    });
    cancelButton.addEventListener('click', () => activeController?.abort());
    startButton.addEventListener('click', async () => {
        const files = Array.from(fileInput.files);
        if (files.length === 0) {
            return;
        }

        startButton.disabled = true;
        fileInput.disabled = true;
        cancelButton.disabled = false;
        hideMessage();
        list.replaceChildren();

        let completed = 0;
        for (const file of files) {
            const row = createProgressRow(file);
            list.append(row.element);
            activeController = new AbortController();
            try {
                if (file.size > maximumSize) {
                    throw new Error(`File exceeds the ${formatBytes(maximumSize)} limit.`);
                }
                const dimensions = await imageDimensions(file);
                row.setState('Authorizing');
                const prepared = await postJson(root.dataset.prepareUrl, {
                    folder: root.dataset.folder,
                    name: file.name,
                    size: file.size,
                    contentType: file.type || 'application/octet-stream',
                    ...dimensions,
                }, activeController.signal);

                row.setState('Uploading');
                const blob = await uploadPresigned(prepared.pathname, file, {
                    access: prepared.access,
                    contentType: prepared.contentType,
                    handleUploadUrl: root.dataset.authorizeUrl,
                    clientPayload: prepared.receipt,
                    multipart: file.size > prepared.multipartThreshold,
                    abortSignal: activeController.signal,
                    onUploadProgress: ({ percentage }) => row.setProgress(percentage),
                });
                if (blob.pathname !== prepared.pathname) {
                    throw new Error('Vercel Blob returned a different pathname.');
                }

                row.setState('Registering in TYPO3');
                await postJson(root.dataset.finalizeUrl, {
                    receipt: prepared.receipt,
                    pathname: blob.pathname,
                    etag: blob.etag,
                }, activeController.signal);
                row.setProgress(100);
                row.setState('Complete', true);
                completed += 1;
            } catch (error) {
                const aborted = error?.name === 'AbortError' || activeController.signal.aborted;
                row.setState(aborted ? 'Cancelled' : (error?.message || 'Upload failed'), false, true);
                if (aborted) {
                    break;
                }
            }
        }

        activeController = null;
        cancelButton.disabled = true;
        fileInput.disabled = false;
        fileInput.value = '';
        startButton.disabled = true;
        showMessage(
            completed === files.length ? `${completed} file(s) uploaded.` : `${completed} of ${files.length} file(s) uploaded.`,
            completed === files.length,
        );
    });

    async function postJson(url, payload, signal) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify(payload),
            signal,
        });
        let data = {};
        try {
            data = await response.json();
        } catch {
            // The status below still gives a useful error for non-JSON proxy responses.
        }
        if (!response.ok) {
            throw new Error(data.error || `Request failed with status ${response.status}.`);
        }
        return data;
    }

    function createProgressRow(file) {
        const element = document.createElement('li');
        element.className = 'list-group-item';
        const header = document.createElement('div');
        header.className = 'd-flex justify-content-between gap-3 mb-2';
        const name = document.createElement('strong');
        name.className = 'text-break';
        name.textContent = file.name;
        const state = document.createElement('span');
        state.className = 'text-body-secondary text-nowrap';
        state.textContent = formatBytes(file.size);
        header.append(name, state);
        const progress = document.createElement('div');
        progress.className = 'progress';
        progress.setAttribute('role', 'progressbar');
        progress.setAttribute('aria-valuemin', '0');
        progress.setAttribute('aria-valuemax', '100');
        const bar = document.createElement('div');
        bar.className = 'progress-bar';
        bar.style.width = '0%';
        progress.append(bar);
        element.append(header, progress);

        return {
            element,
            setProgress(value) {
                const percentage = Math.max(0, Math.min(100, Number(value) || 0));
                bar.style.width = `${percentage}%`;
                progress.setAttribute('aria-valuenow', String(Math.round(percentage)));
            },
            setState(value, success = false, failure = false) {
                state.textContent = value;
                state.className = failure
                    ? 'text-danger text-break text-end'
                    : (success ? 'text-success text-nowrap' : 'text-body-secondary text-nowrap');
                if (success) {
                    bar.classList.add('bg-success');
                } else if (failure) {
                    bar.classList.add('bg-danger');
                }
            },
        };
    }

    async function imageDimensions(file) {
        if (!file.type.startsWith('image/') || file.size > 100 * 1024 * 1024 || typeof createImageBitmap !== 'function') {
            return { width: 0, height: 0 };
        }
        try {
            const bitmap = await createImageBitmap(file);
            const dimensions = { width: bitmap.width, height: bitmap.height };
            bitmap.close();
            return dimensions;
        } catch {
            return { width: 0, height: 0 };
        }
    }

    function showMessage(text, success) {
        message.textContent = text;
        message.className = `alert ${success ? 'alert-success' : 'alert-warning'}`;
    }

    function hideMessage() {
        message.textContent = '';
        message.className = 'alert d-none';
    }
}

function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 bytes';
    }
    const units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / (1024 ** exponent);
    return `${value.toLocaleString(undefined, { maximumFractionDigits: exponent === 0 ? 0 : 1 })} ${units[exponent]}`;
}
