#!/usr/bin/env python3
"""Gera PNG com alpha pra usar em mask_filter do OBS. Sem PIL, só stdlib."""
import zlib, struct, sys

def _png(nome, W, H, alpha):
    rows=[]
    for y in range(H):
        row=bytearray([0])                     # filtro 0
        for x in range(W):
            row += bytes([255,255,255,alpha(x,y)])
        rows.append(bytes(row))
    raw=b''.join(rows)
    def ch(t,d):
        c=struct.pack('>I',len(d))+t+d
        return c+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
    open(nome,'wb').write(b'\x89PNG\r\n\x1a\n'
        +ch(b'IHDR',struct.pack('>IIBBBBB',W,H,8,6,0,0,0))
        +ch(b'IDAT',zlib.compress(raw,9))+ch(b'IEND',b''))
    print(f"✓ {nome} {W}x{H}")

def circulo(nome, N=1080):
    R=N/2; c=R-0.5
    def a(x,y):
        d=((x-c)**2+(y-c)**2)**.5
        return 255 if d<=R-1 else (0 if d>=R else int(255*(R-d)))
    _png(nome,N,N,a)

def radius(nome, W, H, r):
    def a(x,y):
        dx = r-x if x<r else (x-(W-1-r) if x>W-1-r else 0)
        dy = r-y if y<r else (y-(H-1-r) if y>H-1-r else 0)
        if dx>0 and dy>0:
            d=(dx*dx+dy*dy)**.5
            return 255 if d<=r-1 else (0 if d>=r else int(255*(r-d)))
        return 255
    _png(nome,W,H,a)

if __name__=="__main__":
    if len(sys.argv)>1 and sys.argv[1]=="circulo":
        circulo(sys.argv[2] if len(sys.argv)>2 else "molde-circulo.png")
    else:
        radius("molde-radius.png",1280,720,46)
