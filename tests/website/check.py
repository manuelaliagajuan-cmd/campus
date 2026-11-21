"""Check public SEO and local build assets without external network requests."""
import json,re,xml.etree.ElementTree as ET
from pathlib import Path
from html.parser import HTMLParser
root=Path(__file__).resolve().parents[2]/'website'
class Page(HTMLParser):
 def __init__(self,text):
  super().__init__();self.tags=[];self.feed(text)
 def handle_starttag(self,tag,attrs):self.tags.append((tag,dict(attrs)))
titles=[]
for name in ['index.html','comprar.html']:
 text=(root/name).read_text();page=Page(text);titles.append(re.search(r'<title>(.*?)</title>',text,re.S)[1])
 assert len([a for t,a in page.tags if t=='h1'])==1
 canonical=[a['href'] for t,a in page.tags if t=='link' and a.get('rel')=='canonical'];assert canonical==['https://cecalicante.es/'+('' if name=='index.html' else name)]
 for key in ['description','robots']:
  assert any(t=='meta' and a.get('name')==key and a.get('content') for t,a in page.tags)
 for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>',text,re.S):
  data=json.loads(block);assert len(data['@graph'])>=3
 assert 'cdn.tailwindcss.com' not in text
 assert 'translate.google.com/translate_a/element.js' not in text
 for tag,a in page.tags:
  src=a.get('href','') if tag=='link' else a.get('src',a.get('data-src',''))
  if src.startswith(('/css/','/js/','/img/cec-')):assert (root/src.split('?')[0].lstrip('/')).is_file(),src
 if name=='index.html':assert any(t=='source' and a.get('type')=='image/webp' for t,a in page.tags)
assert len(set(titles))==2
xml=ET.parse(root/'sitemap.xml');locations=[e.text for e in xml.findall('.//{*}loc')];assert locations==['https://cecalicante.es/','https://cecalicante.es/comprar.html']
assert 'Sitemap: https://cecalicante.es/sitemap.xml' in (root/'robots.txt').read_text()
assert (root/'img/cec-alicante-clases-720p.mp4').stat().st_size<4_000_000
print('PASS: canonical, unique titles, descriptions, schema, sitemap, robots, static CSS and optimized assets.')
