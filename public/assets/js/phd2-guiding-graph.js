/**
 * phd2-guiding-graph.js — Graphique de guidage PHD2 (erreurs + FFT)
 * Globals attendus : PHD2_RAW, PHD2_PX_TO_ARCSEC, PHD2_STARTED_AT_ISO, PHD2_TRANS
 *   PHD2_TRANS : { axisRaError, axisDecError, axisRaCorr, axisDecCorr,
 *                  labelError, labelCorrection, labelPeriod, labelAmplitude,
 *                  filterFull, filter5min, filter15min, filter30min,
 *                  excludeDither, showDither }
 */

(() => {
    if (typeof PHD2_RAW === 'undefined') return;

    const raw        = PHD2_RAW;
    const pxToArcsec = typeof PHD2_PX_TO_ARCSEC !== 'undefined' ? PHD2_PX_TO_ARCSEC : 1;
    const startMs    = PHD2_STARTED_AT_ISO ? (new Date(PHD2_STARTED_AT_ISO)).getTime() : 0;
    const t          = (typeof PHD2_TRANS !== 'undefined') ? PHD2_TRANS : {};

    const pts = Array.isArray(raw) ? raw : (raw.points || []);
    const ditherWindows = (raw && Array.isArray(raw.dithers)) ? raw.dithers : [];

    // ---- Helpers pour les boutons de fenêtre temporelle ----
    function getExtent() {
        const ds = guidingChart.data.datasets.find(d => d.id === 'raErr') || guidingChart.data.datasets[0];
        const xs = (ds?.data || []).map(p => p.x).filter(v => Number.isFinite(v));
        if (!xs.length) return null;
        return { min: Math.min(...xs), max: Math.max(...xs) };
    }
    function setWindowMins(mins) {
        const ext = getExtent();
        if (!ext) return;
        if (!mins || mins <= 0) {
            guidingChart.scales.x.options.min = undefined;
            guidingChart.scales.x.options.max = undefined;
        } else {
            const max = ext.max;
            guidingChart.scales.x.options.min = max - mins * 60 * 1000;
            guidingChart.scales.x.options.max = max;
        }
        guidingChart.update('none');
        updateStats();
    }

    // ---- Construction des séries ----
    function buildSeries({ excludeDither }) {
        const raErr = [], decErr = [], raCorr = [], decCorr = [];
        for (const p of pts) {
            if (excludeDither && p.isDither === true) continue;
            const tSec = Number(p.t ?? 0);
            const xTs  = startMs + tSec * 1000;
            const dx = (typeof p.dx === 'number') ? p.dx : null;
            const dy = (typeof p.dy === 'number') ? p.dy : null;
            raErr.push({ x: xTs, y: dx === null ? null : dx * pxToArcsec });
            decErr.push({ x: xTs, y: dy === null ? null : dy * pxToArcsec });
            const raDur = Number.isFinite(p.raDurMs) ? p.raDurMs : 0;
            const raDir = (p.raDir || '').toString().toUpperCase();
            const raSigned = (['W', '-', 'NEG'].includes(raDir) ? -1 : 1) * raDur;
            const decDur = Number.isFinite(p.decDurMs) ? p.decDurMs : 0;
            const decDir = (p.decDir || '').toString().toUpperCase();
            const decSigned = (['S', '-', 'NEG'].includes(decDir) ? -1 : 1) * decDur;
            raCorr.push({ x: xTs, y: raSigned });
            decCorr.push({ x: xTs, y: decSigned });
        }
        return { raErr, decErr, raCorr, decCorr };
    }

    function visibleRange() {
        const xScale = guidingChart.scales.x;
        return { min: Number.isFinite(xScale.min) ? xScale.min : undefined, max: Number.isFinite(xScale.max) ? xScale.max : undefined };
    }
    function inWindow(p, vr) {
        if (!vr.min && !vr.max) return true;
        if (vr.min && p.x < vr.min) return false;
        if (vr.max && p.x > vr.max) return false;
        return true;
    }
    function rms(values) {
        let n = 0, acc = 0;
        for (const v of values) { if (!Number.isFinite(v)) continue; acc += v*v; n++; }
        return n ? Math.sqrt(acc/n) : null;
    }
    function peakSigned(values) {
        let best = null;
        for (const v of values) { if (!Number.isFinite(v)) continue; if (best === null || Math.abs(v) > Math.abs(best)) best = v; }
        return best;
    }
    function fmt(valArcsec, toPx=false) {
        if (valArcsec == null) return '—';
        if (toPx) return `(${(valArcsec/pxToArcsec).toFixed(2)} px)`;
        return `${valArcsec.toFixed(2)}″`;
    }

    function updateStats() {
        const exclude = !!document.getElementById('excludeDitherCbx')?.checked;
        const s = buildSeries({ excludeDither: exclude });
        const vr = visibleRange();
        const raErrVis  = s.raErr.filter(p => inWindow(p, vr)).map(p => p.y);
        const decErrVis = s.decErr.filter(p => inWindow(p, vr)).map(p => p.y);
        const raRms  = rms(raErrVis);
        const decRms = rms(decErrVis);
        const totRms = (raRms!=null && decRms!=null) ? Math.sqrt(raRms*raRms + decRms*decRms) : null;
        const raPeak  = peakSigned(raErrVis);
        const decPeak = peakSigned(decErrVis);
        const set = (id, arcsec, showPx=false) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = fmt(arcsec);
            if (showPx) el.textContent += ' ' + fmt(arcsec, true);
        };
        set('rmsRa',  raRms,  true);
        set('rmsDec', decRms, true);
        set('rmsTot', totRms, true);
        set('peakRa',  raPeak,  true);
        set('peakDec', decPeak, true);
    }

    // ---- Plugin bandes de dither ----
    const ditherBandsPlugin = {
        id: 'ditherBandsPlugin',
        beforeDatasetsDraw(chart) {
            const exclude  = document.getElementById('excludeDitherCbx')?.checked;
            const showBands = document.getElementById('showDitherBandsCbx')?.checked;
            if (!showBands || exclude) return;
            const { ctx, chartArea, scales } = chart;
            if (!scales?.x || !pts.length) return;
            ctx.save();
            ctx.fillStyle   = 'rgba(255,255,255,0.47)';
            ctx.strokeStyle = 'rgba(255,255,255,0.78)';
            for (const w of ditherWindows) {
                if (w.startIndex == null || w.endIndex == null) continue;
                const cs = Math.max(0, Math.min(pts.length - 1, w.startIndex));
                const ce = Math.max(0, Math.min(pts.length - 1, w.endIndex));
                const tStart = startMs + Number(pts[cs].t || 0) * 1000;
                const tEnd   = startMs + Number(pts[ce].t || 0) * 1000;
                const x1 = scales.x.getPixelForValue(tStart);
                const x2 = scales.x.getPixelForValue(tEnd);
                const left = Math.min(x1, x2), right = Math.max(x1, x2);
                ctx.fillRect(left, chartArea.top, right - left, chartArea.bottom - chartArea.top);
                ctx.strokeRect(left, chartArea.top, right - left, chartArea.bottom - chartArea.top);
            }
            ctx.restore();
        }
    };
    Chart.register(ditherBandsPlugin);

    const statsPlugin = {
        id: 'statsPlugin',
        afterUpdate() { if (guidingChart) updateStats(); }
    };

    // ---- Construction initiale ----
    let series = buildSeries({ excludeDither: true });

    const guidingChart = new Chart(document.getElementById('guidingChart'), {
        type: 'line',
        data: {
            datasets: [
                { id: 'raErr',  label: t.axisRaError  || 'RA Error',   data: series.raErr,  borderColor: 'rgba(13,110,253,1)',  borderWidth: 1, pointRadius: 0, spanGaps: true, tension: 0, yAxisID: 'yErr' },
                { id: 'decErr', label: t.axisDecError || 'Dec Error',  data: series.decErr, borderColor: 'rgba(220,53,69,1)',   borderWidth: 1, pointRadius: 0, spanGaps: true, tension: 0, yAxisID: 'yErr' },
                { id: 'raCorr',  type: 'bar', label: t.axisRaCorr  || 'RA Corr',  data: series.raCorr,  borderColor: 'rgba(54,162,235,1)',  backgroundColor: 'rgba(54,162,235,0.25)',  borderWidth: 1, yAxisID: 'yCorr', barPercentage: 1.0, categoryPercentage: 1.0 },
                { id: 'decCorr', type: 'bar', label: t.axisDecCorr || 'Dec Corr', data: series.decCorr, borderColor: 'rgba(255,99,132,1)',  backgroundColor: 'rgba(255,99,132,0.25)', borderWidth: 1, yAxisID: 'yCorr', barPercentage: 1.0, categoryPercentage: 1.0 },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true, parsing: false,
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
                legend: { display: true, labels: { color: '#ffffff' } },
                tooltip: {
                    callbacks: {
                        title: (items) => new Date(items[0].parsed.x).toLocaleString(),
                        label: (item) => {
                            const id = item.dataset.id, v = item.parsed.y;
                            if (id === 'raErr' || id === 'decErr') return `${item.dataset.label}: ${v?.toFixed(2)}″`;
                            return `${item.dataset.label}: ${Math.round(v)} ms`;
                        }
                    }
                },
                zoom: {
                    limits: { x: { min: 'original', max: 'original' } },
                    pan: { enabled: true, mode: 'x', modifierKey: 'shift', onPanComplete: () => updateStats() },
                    zoom: {
                        wheel: { enabled: true }, pinch: { enabled: true },
                        drag: { enabled: true, backgroundColor: 'rgba(0,0,0,0.1)', borderColor: 'rgba(0,0,0,0.3)', threshold: 8 },
                        mode: 'x', onZoomComplete: () => updateStats()
                    }
                },
                ditherBandsPlugin
            },
            scales: {
                x: {
                    type: 'time',
                    time: { displayFormats: { millisecond: 'HH:mm:ss', second: 'HH:mm:ss', minute: 'HH:mm', hour: 'HH:mm' }, tooltipFormat: 'PPpp' },
                    ticks: { color: '#ffffff' }, grid: { color: 'rgba(0,0,0,0.06)' },
                    title: { display: true, color: '#ffffff' }
                },
                yErr: {
                    position: 'left',
                    title: { display: true, text: t.labelError || 'Error (″)', color: '#ffffff' },
                    grid: { color: 'rgba(255,255,255,0.4)' },
                    beginAtZero: true, suggestedMin: -2, suggestedMax: 2,
                    ticks: { callback: (v) => (v === 0 ? '0' : (v > 0 ? '+' + v : v)) + '″', color: '#ffffff' },
                },
                yCorr: {
                    position: 'right',
                    title: { display: true, text: t.labelCorrection || 'Correction (ms)', color: '#ffffff' },
                    grid: { display: false }, beginAtZero: true,
                    ticks: { color: '#ffffff' }, suggestedMin: -200, suggestedMax: 200
                }
            },
        }
    });
    Chart.register(statsPlugin);

    // ---- Contrôles ----
    document.getElementById('excludeDitherCbx').addEventListener('change', (e) => {
        const s = buildSeries({ excludeDither: !!e.target.checked });
        guidingChart.data.datasets.find(d => d.id === 'raErr').data = s.raErr;
        guidingChart.data.datasets.find(d => d.id === 'decErr').data = s.decErr;
        guidingChart.data.datasets.find(d => d.id === 'raCorr').data = s.raCorr;
        guidingChart.data.datasets.find(d => d.id === 'decCorr').data = s.decCorr;
        guidingChart.update('none');
        updateStats(); updateFFT();
    });
    document.getElementById('showDitherBandsCbx').addEventListener('change', () => {
        guidingChart.update('none'); updateStats(); updateFFT();
    });
    document.getElementById('graphControls').addEventListener('click', e => {
        const mins = e.target.getAttribute('data-mins');
        if (mins !== null) setWindowMins(parseInt(mins, 10));
        updateStats(); updateFFT();
    });

    updateStats();
    setWindowMins(0);

    // ---- FFT ----
    function nextPow2(n) { let p=1; while(p<n) p<<=1; return p; }
    function hannWindow(N) { const w=new Float64Array(N); for(let i=0;i<N;i++) w[i]=0.5*(1-Math.cos(2*Math.PI*i/(N-1))); return w; }
    function fftRadix2(re, im) {
        const n = re.length; let ii=0;
        for (let j=1;j<n-1;j++) { let bit=n>>1; for (;ii&bit;bit>>=1) ii^=bit; ii^=bit; if (j<ii){[re[j],re[ii]]=[re[ii],re[j]];[im[j],im[ii]]=[im[ii],im[j]];} }
        for (let len=2;len<=n;len<<=1) {
            const ang=-2*Math.PI/len, wlenRe=Math.cos(ang), wlenIm=Math.sin(ang);
            for (let i=0;i<n;i+=len) {
                let wRe=1,wIm=0;
                for (let j=0;j<(len>>1);j++) {
                    const uRe=re[i+j],uIm=im[i+j],vRe=re[i+j+(len>>1)]*wRe-im[i+j+(len>>1)]*wIm,vIm=re[i+j+(len>>1)]*wIm+im[i+j+(len>>1)]*wRe;
                    re[i+j]=uRe+vRe;im[i+j]=uIm+vIm;re[i+j+(len>>1)]=uRe-vRe;im[i+j+(len>>1)]=uIm-vIm;
                    const nwRe=wRe*wlenRe-wIm*wlenIm,nwIm=wRe*wlenIm+wIm*wlenRe; wRe=nwRe;wIm=nwIm;
                }
            }
        }
    }
    function resampleUniform(tMs, y, dtMs) {
        const pts=[]; for(let i=0;i<tMs.length;i++) if(Number.isFinite(y[i])) pts.push([tMs[i],y[i]]);
        if (pts.length<8) return null;
        const t0=pts[0][0],t1=pts[pts.length-1][0],N=Math.floor((t1-t0)/dtMs)+1;
        const out=new Float64Array(N); let k=0;
        for(let i=0;i<N;i++){
            const tx=t0+i*dtMs;
            while(k+1<pts.length&&pts[k+1][0]<tx) k++;
            if(k+1>=pts.length){out[i]=pts[pts.length-1][1];break;}
            const [tA,yA]=pts[k],[tB,yB]=pts[k+1];
            out[i]=yA*(1-(tx-tA)/(tB-tA))+yB*((tx-tA)/(tB-tA));
        }
        return {t0,N,y:out,dtSec:dtMs/1000};
    }
    function spectrumFromSeries(tMs, yArcsec) {
        if (tMs.length<8) return {period:[],amp:[]};
        const dts=[]; for(let i=1;i<tMs.length;i++){const d=tMs[i]-tMs[i-1];if(d>0)dts.push(d);}
        dts.sort((a,b)=>a-b);
        const dtMs=dts.length?dts[Math.floor(dts.length/2)]:1000;
        const res=resampleUniform(tMs,yArcsec,dtMs);
        if(!res) return {period:[],amp:[]};
        const {y,N,dtSec}=res;
        let mean=0; for(let i=0;i<N;i++) mean+=y[i]; mean/=N;
        for(let i=0;i<N;i++) y[i]-=mean;
        const w=hannWindow(N); for(let i=0;i<N;i++) y[i]*=w[i];
        const Nfft=nextPow2(N);
        const re=new Float64Array(Nfft),im=new Float64Array(Nfft); re.set(y);
        fftRadix2(re,im);
        const outPeriod=[],outAmp=[];
        for(let k=1;k<=Math.floor(Nfft/2);k++){
            const mag=Math.hypot(re[k],im[k]);
            outPeriod.push(1/(k/(Nfft*dtSec)));
            outAmp.push((2*mag/Nfft)/0.5);
        }
        return {period:outPeriod,amp:outAmp};
    }

    const fftChart = new Chart(document.getElementById('fftChart'), {
        type: 'line',
        data: { datasets: [
            { id:'fftRA',  label: t.axisRaError  ? `${t.axisRaError} FFT (″)`  : 'RA FFT (″)',  data:[], borderWidth:1, pointRadius:0, spanGaps:true, tension:0, borderColor:'rgba(13,110,253,1)' },
            { id:'fftDEC', label: t.axisDecError ? `${t.axisDecError} FFT (″)` : 'Dec FFT (″)', data:[], borderWidth:1, pointRadius:0, spanGaps:true, tension:0, borderColor:'rgba(220,53,69,1)' },
        ]},
        options: {
            parsing:false, responsive:true, maintainAspectRatio:true,
            interaction:{mode:'nearest',intersect:false},
            plugins:{
                legend:{display:true},
                tooltip:{callbacks:{title:(items)=>`Period: ${items[0].parsed.x.toFixed(0)} s`, label:(i)=>`${i.dataset.label}: ${i.parsed.y.toFixed(3)}″`}}
            },
            scales:{
                x:{type:'logarithmic',title:{display:true,text:t.labelPeriod||'Period (s)'},min:2,ticks:{callback:(v)=>(v>=1?Math.round(v):v)}},
                y:{title:{display:true,text:t.labelAmplitude||'Amplitude (″)'},beginAtZero:true}
            }
        }
    });

    function getVisibleWindow() {
        const s=guidingChart.scales.x;
        return {xmin:s.min??s.getUserBounds().min??null,xmax:s.max??s.getUserBounds().max??null};
    }
    function updateFFT() {
        const raDs=guidingChart.data.datasets.find(d=>d.id==='raErr');
        const deDs=guidingChart.data.datasets.find(d=>d.id==='decErr');
        if(!raDs||!deDs) return;
        const {xmin,xmax}=getVisibleWindow();
        const filterByWindow=(p)=>(!Number.isFinite(xmin)||p.x>=xmin)&&(!Number.isFinite(xmax)||p.x<=xmax);
        const tRa=[],yRa=[],tDe=[],yDe=[];
        for(const p of raDs.data){if(Number.isFinite(p.y)&&filterByWindow(p)){tRa.push(p.x);yRa.push(p.y);}}
        for(const p of deDs.data){if(Number.isFinite(p.y)&&filterByWindow(p)){tDe.push(p.x);yDe.push(p.y);}}
        const ra=spectrumFromSeries(tRa,yRa),de=spectrumFromSeries(tDe,yDe);
        fftChart.data.datasets[0].data=ra.period.map((P,i)=>({x:P,y:ra.amp[i]}));
        fftChart.data.datasets[1].data=de.period.map((P,i)=>({x:P,y:de.amp[i]}));
        const allT=tRa.length?tRa:tDe;
        if(allT.length>=2){
            const dts=[]; for(let i=1;i<allT.length;i++){const d=allT[i]-allT[i-1];if(d>0)dts.push(d);}
            dts.sort((a,b)=>a-b);
            const dt=dts[Math.floor(dts.length/2)]||1000,durSec=(allT[allT.length-1]-allT[0])/1000;
            fftChart.options.scales.x.min=Math.max(2*dt/1000,2);
            fftChart.options.scales.x.max=Math.max(10,Math.floor(durSec));
        }
        fftChart.update('none');
    }

    if(guidingChart?.options?.plugins?.zoom){
        guidingChart.options.plugins.zoom.zoom.onZoomComplete=()=>{updateFFT();updateStats();};
        guidingChart.options.plugins.zoom.pan.onPanComplete=()=>{updateFFT();updateStats();};
    }
    updateFFT();
})();
