import re


def _split_long(text: str, size: int) -> list[str]:
    pieces = []
    while len(text) > size:
        boundary = max(text.rfind(". ", 0, size), text.rfind("? ", 0, size), text.rfind("! ", 0, size), text.rfind(" ", 0, size))
        if boundary < size // 2:
            boundary = size
        pieces.append(text[:boundary].strip())
        text = text[boundary:].strip()
    if text:
        pieces.append(text)
    return pieces


def chunk_text(text: str, chunk_size: int = 850, overlap: int = 100) -> list[str]:
    """Keep headings and nearby paragraphs together; retain a heading on split parts."""
    if chunk_size <= 0:
        raise ValueError("chunk_size must be greater than 0")
    if overlap < 0 or overlap >= chunk_size:
        raise ValueError("overlap must be non-negative and smaller than chunk_size")

    blocks = []
    heading = ""
    for raw in text.replace("\r\n", "\n").split("\n"):
        line = raw.strip()
        if not line:
            continue
        if line.startswith("#"):
            heading = line
        else:
            blocks.append(f"{heading}\n{line}" if heading else line)

    chunks = []
    current = ""
    for block in blocks:
        for piece in _split_long(block, chunk_size):
            candidate = f"{current}\n\n{piece}" if current else piece
            if len(candidate) <= chunk_size:
                current = candidate
                continue
            if current:
                chunks.append(current)
            heading_match = re.match(r"^(#{1,6} .+?)\n", piece)
            carry = current[-overlap:].lstrip() if overlap and current else ""
            if heading_match:
                carry = heading_match.group(1) + ("\n" + carry if carry else "")
            current = f"{carry}\n\n{piece}" if carry else piece
            if len(current) > chunk_size:
                current = piece
    if current:
        chunks.append(current)
    return chunks
