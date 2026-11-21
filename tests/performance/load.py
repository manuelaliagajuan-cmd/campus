"""Authenticated API load against isolated fixtures, never production."""
import concurrent.futures, json, os, statistics, time, urllib.request, urllib.parse, urllib.error
from pathlib import Path
base=os.environ.get('CEC_PERF_URL','http://127.0.0.1:8787')
if urllib.parse.urlsplit(base).hostname not in ('127.0.0.1','localhost'):raise SystemExit('Solo se permite un entorno local de prueba.')
fixture=json.loads(Path(os.environ['CEC_PERF_FIXTURE']).read_text());clients=fixture['clients'];samples=[];errors=[]
def poll(client, validate_presence=True):
 started=time.perf_counter()
 try:
  request=urllib.request.Request(base+client['url'],headers={'Cookie':client['cookie'],'Accept':'application/json'})
  with urllib.request.urlopen(request,timeout=12) as response:
   data=json.load(response)
   if response.status!=200 or (validate_presence and len(data.get('peers',[]))!=2):raise ValueError('Respuesta de aula inesperada')
  return round((time.perf_counter()-started)*1000,2),None
 except Exception as e:return round((time.perf_counter()-started)*1000,2),type(e).__name__
# Real cookies, database sessions and 40 simultaneous authenticated connections.
with concurrent.futures.ThreadPoolExecutor(max_workers=40) as pool:
 # Warm session/cache and refresh every participant before measuring presence.
 warmup=list(pool.map(lambda client:poll(client,False),clients))
 if any(error for _,error in warmup):raise SystemExit('Error HTTP al preparar las aulas aisladas.')
 for round_number in range(20):
  began=time.perf_counter()
  for duration,error in pool.map(poll,clients):
   samples.append(duration)
   if error:errors.append(error)
  if round_number==0 and errors:break
  time.sleep(max(0,1.5-(time.perf_counter()-began)))
ordered=sorted(samples);p95=ordered[max(0,int(len(ordered)*.95)-1)]
report={'environment':'isolated HTTP API benchmark; not IONOS capacity certification','concurrent_accounts':len(clients),'classrooms':fixture['rooms'],'source_students':fixture['source_students'],'requests':len(samples),'errors':len(errors),'median_ms':round(statistics.median(samples),2),'p95_ms':p95,'max_ms':max(samples),'database':os.environ.get('DB_CONNECTION','sqlite')}
print(json.dumps(report,ensure_ascii=False))
Path(os.environ.get('CEC_PERF_REPORT','/tmp/cec-capacity-report.json')).write_text(json.dumps(report,indent=2)+'\n')
if errors or p95>1500:raise SystemExit('La prueba de concurrencia no alcanza el objetivo de referencia.')
