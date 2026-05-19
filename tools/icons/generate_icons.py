#!/usr/bin/env python3
"""
Generate simple PWA icons for FFP3
Creates PNG files with FFP3 text on colored background
"""

try:
    from PIL import Image, ImageDraw, ImageFont
    HAS_PIL = True
except ImportError:
    HAS_PIL = False
    print("Warning: PIL not available, creating minimal placeholder PNGs")

import os

SIZES = [72, 96, 128, 144, 152, 192, 384, 512]
BG_COLOR = (0, 139, 116)  # #008B74
TEXT_COLOR = (255, 255, 255)

def create_icon_with_pil(size):
    """Create icon using PIL (if available)"""
    img = Image.new('RGB', (size, size), BG_COLOR)
    draw = ImageDraw.Draw(img)
    
    # Try to use a font, fallback to default
    try:
        font_size = size // 3
        font = ImageFont.truetype("arial.ttf", font_size)
    except:
        font = ImageFont.load_default()
    
    # Draw text "FFP3" centered
    text = "FFP3"
    bbox = draw.textbbox((0, 0), text, font=font)
    text_width = bbox[2] - bbox[0]
    text_height = bbox[3] - bbox[1]
    x = (size - text_width) // 2
    y = (size - text_height) // 2
    
    draw.text((x, y), text, fill=TEXT_COLOR, font=font)
    
    # Add decorative circle
    circle_size = int(size * 0.15)
    circle_y = int(size * 0.75)
    circle_x = size // 2
    draw.ellipse([
        circle_x - circle_size//2, 
        circle_y - circle_size//2,
        circle_x + circle_size//2, 
        circle_y + circle_size//2
    ], fill=TEXT_COLOR)
    
    return img

def create_minimal_png(size):
    """Create a minimal valid PNG (solid color square) without PIL"""
    # This creates a minimal valid PNG file (1x1 pixel, scaled)
    # PNG signature + IHDR chunk + IDAT chunk + IEND chunk
    import struct
    import zlib
    
    # PNG signature
    png_sig = b'\x89PNG\r\n\x1a\n'
    
    # IHDR chunk (image header)
    width = height = size
    bit_depth = 8
    color_type = 2  # RGB
    compression = 0
    filter_method = 0
    interlace = 0
    
    ihdr_data = struct.pack('>IIBBBBB', width, height, bit_depth, color_type, 
                            compression, filter_method, interlace)
    ihdr_chunk = struct.pack('>I', 13) + b'IHDR' + ihdr_data + struct.pack('>I', zlib.crc32(b'IHDR' + ihdr_data))
    
    # IDAT chunk (image data) - create solid color
    raw_data = bytearray()
    r, g, b = BG_COLOR
    for y in range(height):
        raw_data.append(0)  # filter type
        for x in range(width):
            raw_data.extend([r, g, b])
    
    compressed = zlib.compress(bytes(raw_data), 9)
    idat_chunk = struct.pack('>I', len(compressed)) + b'IDAT' + compressed + struct.pack('>I', zlib.crc32(b'IDAT' + compressed))
    
    # IEND chunk
    iend_chunk = struct.pack('>I', 0) + b'IEND' + struct.pack('>I', zlib.crc32(b'IEND'))
    
    return png_sig + ihdr_chunk + idat_chunk + iend_chunk

# Generate icons
script_dir = os.path.dirname(os.path.abspath(__file__))
os.chdir(script_dir)

for size in SIZES:
    filename = f"icon-{size}.png"
    
    if HAS_PIL:
        img = create_icon_with_pil(size)
        img.save(filename, 'PNG', optimize=True)
    else:
        # Create minimal PNG without PIL
        png_data = create_minimal_png(size)
        with open(filename, 'wb') as f:
            f.write(png_data)
    
    print(f"✓ Generated {filename}")

print(f"\n✅ All {len(SIZES)} icons generated successfully!")
print("Note: For better icons, consider using a professional design tool.")

