/**
 * Convert PDF pages to PNG images (base64) for OpenAI vision.
 * Loads pdf.js from CDN (same approach as retail import-bill).
 */

const RENDER_SCALE = 2;
/** Max pages sent to AI (keeps latency/tokens under control). */
const MAX_PDF_PAGES = 4;

function getPdfLib(): Promise<any> {
  const w = window as any;
  if (w.pdfjsLib) {
    w.pdfjsLib.GlobalWorkerOptions.workerSrc =
      w.pdfjsLib.GlobalWorkerOptions.workerSrc ||
      'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    return Promise.resolve(w.pdfjsLib);
  }
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    script.async = true;
    script.onload = () => {
      const lib = (window as any).pdfjsLib;
      if (!lib) {
        reject(new Error('PDF.js failed to load.'));
        return;
      }
      lib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      resolve(lib);
    };
    script.onerror = () => reject(new Error('Could not load PDF.js from CDN.'));
    document.head.appendChild(script);
  });
}

function fileToArrayBuffer(file: File): Promise<ArrayBuffer> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as ArrayBuffer);
    reader.onerror = () => reject(new Error('Failed to read file'));
    reader.readAsArrayBuffer(file);
  });
}

export async function pdfFileToImages(file: File): Promise<{ base64: string; mimeType: string }[]> {
  const lib = await getPdfLib();
  const arrayBuffer = await fileToArrayBuffer(file);
  const pdf = await lib.getDocument({ data: arrayBuffer }).promise;
  const pagesToRender = Math.min(pdf.numPages as number, MAX_PDF_PAGES);
  const result: { base64: string; mimeType: string }[] = [];

  for (let i = 1; i <= pagesToRender; i++) {
    const page = await pdf.getPage(i);
    const viewport = page.getViewport({ scale: RENDER_SCALE });
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Could not get canvas context');
    await page.render({ canvasContext: ctx, viewport }).promise;
    const dataUrl = canvas.toDataURL('image/png');
    const base64 = dataUrl.includes('base64,') ? dataUrl.split('base64,')[1] : dataUrl;
    result.push({ base64, mimeType: 'image/png' });
    await new Promise<void>((r) => setTimeout(r, 0));
  }

  return result;
}

export async function fileToBase64Image(file: File): Promise<{ base64: string; mimeType: string }> {
  const mimeType = file.type || 'image/png';
  const dataUrl = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = () => reject(new Error('Failed to read image'));
    reader.readAsDataURL(file);
  });
  const base64 = dataUrl.includes('base64,') ? dataUrl.split('base64,')[1] : dataUrl;
  return { base64, mimeType: mimeType === 'image/jpg' ? 'image/jpeg' : mimeType };
}
