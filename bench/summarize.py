# Summarises a benchmark directory holding octane.csv and control.csv (vegeta encode --to csv) plus started.txt.
# Usage: python3 bench/summarize.py DIR OUT.json LABEL
import csv,collections,json,sys
d=sys.argv[1]; out=sys.argv[2]; label=sys.argv[3]
def lat(f): return [(int(r[0])/1e9,int(r[2])/1e6,r[1]) for r in csv.reader(open(f))]
o=lat(d+'/octane.csv'); c=lat(d+'/control.csv')
t0=min(t for t,_,_ in o)
def pct(v,p): v=sorted(v); return round(v[min(len(v)-1,int(len(v)*p))],2) if v else None
def summ(v): return {'requests':len(v),'p50':pct(v,.5),'p90':pct(v,.9),'p95':pct(v,.95),'p99':pct(v,.99),'p99_9':pct(v,.999),'max':round(max(v),2),'over_10ms':sum(x>10 for x in v),'over_50ms':sum(x>50 for x in v)}
m=collections.defaultdict(lambda:[[],[]])
for t,l,_ in o: m[int((t-t0)//60)][0].append(l)
for t,l,_ in c: m[int((t-t0)//60)][1].append(l)
cmax=collections.defaultdict(float)
for t,l,_ in c: cmax[int(t-t0)]=max(cmax[int(t-t0)],l)
qs={s for s in cmax if cmax[s]<10}
oq=[l for t,l,_ in o if int(t-t0) in qs]
res={'label':label,'started_utc':open(d+'/started.txt').read().strip(),
 'octane_status_codes':dict(collections.Counter(code for _,_,code in o)),
 'octane':summ([l for _,l,_ in o]),'control':summ([l for _,l,_ in c]),
 'quiet_seconds':{'definition':'seconds in which every control request finished under 10 ms','count':len(qs),'octane':summ(oq) if oq else None},
 'per_minute':[{'minute':k,'octane':summ(m[k][0]),'control':summ(m[k][1])} for k in sorted(m) if k>=0 and m[k][0]]}
json.dump(res,open(out,'w'),indent=1)
print(json.dumps({k:res[k] for k in ('octane','control','quiet_seconds')}))
