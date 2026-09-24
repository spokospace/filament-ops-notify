"""Thumbnail for the filamentphp.com plugin card (art/thumbnail.jpg, 1600x900).

Two steps, so the title never goes through the diffusion model (it garbles
letters): FLUX.2 [klein] paints the backdrop, and Pillow lays the name on top
with the cover's typography (Inter, the eyebrow, the blue gradient).

    python art/thumbnail.py               # compose from the committed backdrop
    python art/thumbnail.py --generate    # repaint the backdrop first (needs ComfyUI)
    python art/thumbnail.py --generate --seed 7

Generation talks to a local ComfyUI (COMFY_URL, default http://127.0.0.1:8188)
with klein 9B, Qwen3-8B as the text encoder and the Flux 2 VAE. The seed is
fixed, so a re-run reproduces the committed backdrop on the same model files.
Inter is fetched from google/fonts into the system temp dir on first use.
"""

import json
import os
import sys
import tempfile
import time
import urllib.parse
import urllib.request

from PIL import Image, ImageDraw, ImageFont

ART = os.path.dirname(os.path.abspath(__file__))
BACKDROP = os.path.join(ART, "thumbnail-backdrop.jpg")
OUT = os.path.join(ART, "thumbnail.jpg")
W, H = 1600, 900

COMFY = os.environ.get("COMFY_URL", "http://127.0.0.1:8188")
SEED = 60942
PROMPT = (
    "Minimal isometric 3D illustration for a software product thumbnail, wide 16:9, light theme. "
    "A bright blue paper airplane flies from the lower left toward the upper right, leaving a curved "
    "dotted trail. Along the trail, three small rounded chat bubbles hover like forum topics, each a "
    "different soft color: green with a check-mark shape, amber with a wrench shape, red with a small "
    "warning-triangle shape. Clean off-white background with a very light blue gradient, soft ambient "
    "occlusion, matte plastic materials, calm, spacious, product-render quality. "
    "no text, no letters, no words, no numbers, no logos, no watermark, no people, no screens, "
    "no user interface."
)

INTER_URL = "https://github.com/google/fonts/raw/main/ofl/inter/Inter%5Bopsz%2Cwght%5D.ttf"
MUTED = (71, 85, 105)
ACCENT = (42, 171, 238)
GRADIENT = [(42, 171, 238), (31, 143, 214), (21, 101, 168)]  # h1 .bottom in cover.html


def api(route, data=None, headers=None):
    req = urllib.request.Request(COMFY + route, data=data, headers=headers or {})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read()


def generate(seed):
    """Queue the klein graph, wait for it, write the 16:9 backdrop."""
    gw, gh = 1600, 896  # klein wants multiples of 64; cropped to 16:9 below
    graph = {
        "1": {"class_type": "UNETLoader", "inputs": {"unet_name": "flux-2-klein-9b-nvfp4.safetensors", "weight_dtype": "default"}},
        "2": {"class_type": "CLIPLoader", "inputs": {"clip_name": "qwen_3_8b_fp8mixed.safetensors", "type": "flux2", "device": "default"}},
        "3": {"class_type": "VAELoader", "inputs": {"vae_name": "flux2-vae.safetensors"}},
        "4": {"class_type": "CLIPTextEncode", "inputs": {"clip": ["2", 0], "text": PROMPT}},
        "5": {"class_type": "ConditioningZeroOut", "inputs": {"conditioning": ["4", 0]}},
        "6": {"class_type": "CFGGuider", "inputs": {"model": ["1", 0], "positive": ["4", 0], "negative": ["5", 0], "cfg": 1.0}},
        "7": {"class_type": "EmptyFlux2LatentImage", "inputs": {"width": gw, "height": gh, "batch_size": 1}},
        "8": {"class_type": "Flux2Scheduler", "inputs": {"steps": 6, "width": gw, "height": gh}},
        "9": {"class_type": "KSamplerSelect", "inputs": {"sampler_name": "euler"}},
        "10": {"class_type": "RandomNoise", "inputs": {"noise_seed": seed}},
        "11": {"class_type": "SamplerCustomAdvanced", "inputs": {"noise": ["10", 0], "guider": ["6", 0], "sampler": ["9", 0], "sigmas": ["8", 0], "latent_image": ["7", 0]}},
        "12": {"class_type": "VAEDecode", "inputs": {"samples": ["11", 0], "vae": ["3", 0]}},
        "13": {"class_type": "SaveImage", "inputs": {"images": ["12", 0], "filename_prefix": "ops-notify/thumbnail"}},
    }
    body = json.dumps({"prompt": graph, "client_id": "ops-notify-thumbnail"}).encode()
    queued = json.loads(api("/prompt", body, {"content-type": "application/json"}))
    pid = queued["prompt_id"]
    print(f"queued seed {seed} as {pid}", flush=True)

    for _ in range(300):
        time.sleep(4)
        entry = json.loads(api(f"/history/{pid}")).get(pid)
        if not entry:
            continue
        if entry.get("status", {}).get("status_str") == "error":
            sys.exit(f"ComfyUI failed: {json.dumps(entry['status'].get('messages'))[:800]}")
        if entry.get("outputs"):
            break
    else:
        sys.exit("timed out waiting for ComfyUI")

    image = next(i for o in entry["outputs"].values() for i in o.get("images", []))
    query = urllib.parse.urlencode({"filename": image["filename"], "subfolder": image.get("subfolder", ""), "type": image["type"]})
    raw = os.path.join(tempfile.gettempdir(), "ops-notify-thumbnail.png")
    with open(raw, "wb") as f:
        f.write(api("/view?" + query))

    im = Image.open(raw).convert("RGB")
    nw = round(im.width * H / im.height)
    im = im.resize((nw, H), Image.LANCZOS)
    x = (nw - W) // 2
    im.crop((x, 0, x + W, H)).save(BACKDROP, "JPEG", quality=95, optimize=True)
    print(f"wrote {os.path.relpath(BACKDROP)}")


def inter(size, weight):
    path = os.path.join(tempfile.gettempdir(), "Inter-variable.ttf")
    if not os.path.exists(path):
        urllib.request.urlretrieve(INTER_URL, path)
    font = ImageFont.truetype(path, size)
    font.set_variation_by_axes([32, weight])  # optical size, weight
    return font


def tracked(draw, xy, text, font, fill, tracking):
    """Draw text with letter spacing; Pillow has no tracking of its own."""
    x, y = xy
    for ch in text:
        draw.text((x, y), ch, font=font, fill=fill)
        x += draw.textlength(ch, font=font) + tracking


def gradient(width, height):
    im = Image.new("RGB", (width, height))
    px = im.load()
    for x in range(width):
        t = x / max(width - 1, 1)
        a, b, u = (GRADIENT[0], GRADIENT[1], t / .55) if t < .55 else (GRADIENT[1], GRADIENT[2], (t - .55) / .45)
        colour = tuple(round(a[i] + (b[i] - a[i]) * u) for i in range(3))
        for y in range(height):
            px[x, y] = colour
    return im


def compose():
    """Lay the eyebrow and the title over the backdrop, top left, like the cover."""
    im = Image.open(BACKDROP).convert("RGB")
    draw = ImageDraw.Draw(im)
    x, y = 96, 96

    eyebrow = 19
    r = 7
    draw.ellipse((x, y + eyebrow // 2 - r + 2, x + 2 * r, y + eyebrow // 2 + r + 2), fill=ACCENT)
    tracked(draw, (x + 2 * r + 16, y), "FILAMENT PLUGIN", inter(eyebrow, 700), MUTED, eyebrow * .32)

    size = 104
    font = inter(size, 900)
    text = "Ops Notify"
    tracking = -size * .035
    width = int(draw.textlength(text, font=font) + tracking * (len(text) - 1)) + 20
    mask = Image.new("L", (width, size + 40), 0)
    tracked(ImageDraw.Draw(mask), (0, 0), text, font, 255, tracking)
    im.paste(gradient(mask.width, mask.height), (x - 4, y + eyebrow + 26), mask)

    im.save(OUT, "JPEG", quality=92, optimize=True)
    print(f"wrote {os.path.relpath(OUT)} ({W}x{H})")


if __name__ == "__main__":
    args = sys.argv[1:]
    if "--generate" in args or not os.path.exists(BACKDROP):
        seed = int(args[args.index("--seed") + 1]) if "--seed" in args else SEED
        generate(seed)
    compose()
