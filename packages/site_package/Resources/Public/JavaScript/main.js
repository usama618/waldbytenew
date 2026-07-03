(function(){
  var hdr = document.querySelector('#hdr');
  addEventListener('scroll', function(){ if(hdr) hdr.classList.toggle('scrolled', scrollY>40); }, {passive:true});

  /* mobile burger + level-2 accordions */
  var burger = document.querySelector('.burger');
  var mainNav = document.querySelector('nav.main');
  var navScrollY = 0;
  var navLocked = false;
  function setNavOpen(open){
    if (!burger) return;
    if (open && !navLocked) {
      navScrollY = window.scrollY || document.documentElement.scrollTop || 0;
      document.body.classList.add('nav-open');
      document.body.style.position = 'fixed';
      document.body.style.top = '-' + navScrollY + 'px';
      document.body.style.left = '0';
      document.body.style.right = '0';
      document.body.style.width = '100%';
      document.body.style.overflow = 'hidden';
      navLocked = true;
      if (mainNav) mainNav.scrollTop = 0;
    } else if (!open && navLocked) {
      document.body.classList.remove('nav-open');
      document.body.style.position = '';
      document.body.style.top = '';
      document.body.style.left = '';
      document.body.style.right = '';
      document.body.style.width = '';
      document.body.style.overflow = '';
      navLocked = false;
      window.scrollTo(0, navScrollY);
    } else {
      document.body.classList.toggle('nav-open', open);
    }
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (burger){
    burger.addEventListener('click', function(){
      setNavOpen(!document.body.classList.contains('nav-open'));
    });
  }
  document.querySelectorAll('.nav-item .car').forEach(function(car){
    car.addEventListener('click', function(e){
      if (matchMedia('(max-width: 900px)').matches){
        e.preventDefault(); e.stopPropagation();
        car.closest('.nav-item').classList.toggle('open');
      }
    });
  });
  document.querySelectorAll('nav.main a').forEach(function(a){
    a.addEventListener('click', function(){ setNavOpen(false); });
  });
  addEventListener('keydown', function(e){
    if (e.key === 'Escape') setNavOpen(false);
  });
  addEventListener('resize', function(){
    if (!matchMedia('(max-width: 900px)').matches) setNavOpen(false);
  });

  var dotTargets = ['start','leistungen','projekte','prozess','kontakt'];
  var dots = Array.prototype.slice.call(document.querySelectorAll('.dots a'));
  var io = new IntersectionObserver(function(es){ es.forEach(function(e){
    if (e.isIntersecting) {
      e.target.classList.add('vis');
      var i = dotTargets.indexOf(e.target.id);
      if (i >= 0) dots.forEach(function(d,k){ d.classList.toggle('on', k === i); });
    }
  });}, {threshold:0.25});
  document.querySelectorAll('section, .hero').forEach(function(s){ io.observe(s); });

  if (typeof THREE === 'undefined') return;

  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var mobile = matchMedia('(max-width: 640px)').matches;
  var rnd = function(a,b){ return a+Math.random()*(b-a); };
  var g3 = function(){ return (Math.random()+Math.random()+Math.random())/3; };

  function forest(i,n){
    var t=i/n;
    if (t<0.62){
      var cl=[[0,6.5,0,6.5,4.2,5.5],[-4.5,3.8,-1.5,3,2.4,2.6],[4.8,4.6,1,3.4,2.6,2.8]];
      var c=cl[Math.floor(Math.random()*3)];
      var th=Math.random()*Math.PI*2, ph=Math.acos(rnd(-1,1)), r=Math.pow(Math.random(),.45);
      return [c[0]+Math.sin(ph)*Math.cos(th)*c[3]*r, c[1]+Math.cos(ph)*c[4]*r, c[2]+Math.sin(ph)*Math.sin(th)*c[5]*r];
    } else if (t<0.84){
      if (Math.random()<0.45){ var y=rnd(-7,4.5); return [Math.sin((y+7)*0.25)*0.6+rnd(-.35,.35), y, rnd(-.35,.35)]; }
      var by=rnd(-1.5,4), ang=Math.random()*Math.PI*2, len=rnd(1.5,5)*(1-(by+1.5)/7), u=Math.random();
      return [Math.cos(ang)*len*u+rnd(-.2,.2), by+u*rnd(.8,2.2), Math.sin(ang)*len*u+rnd(-.2,.2)];
    }
    return [rnd(-17,17), rnd(-8,11), rnd(-9,6)];
  }
  function grid(i,n){
    var t=i/n;
    if (t<0.5){
      var x=rnd(-18,18), z=rnd(-18,6);
      if (Math.random()<0.5) x=Math.round(x/1.5)*1.5; else z=Math.round(z/1.5)*1.5;
      return [x, -5.5+Math.sin(x*0.4)*Math.cos(z*0.4)*0.8, z];
    } else if (t<0.86){
      var k=Math.floor(rnd(0,4)), cx=[-9.5,-3.2,3.2,9.5][k], x2, y2;
      if (Math.random()<0.55){
        if (Math.random()<0.5){ x2=rnd(-1.9,1.9); y2=Math.random()<0.5?-2.6:2.6; }
        else { x2=Math.random()<0.5?-1.9:1.9; y2=rnd(-2.6,2.6); }
      } else { x2=rnd(-1.9,1.9); y2=rnd(-2.6,2.6); }
      return [cx+x2, 2.2+y2+(k%2)*0.8, rnd(-.3,.3)-2];
    }
    return [Math.round(rnd(-18,18)/1.5)*1.5, rnd(-5.5,7), Math.round(rnd(-18,6)/1.5)*1.5];
  }
  function tunnel(i,n){
    var t=i/n, th=Math.random()*Math.PI*2;
    if (t<0.78){ var z=rnd(-50,14), r=7+Math.sin(z*0.3)*0.8+g3()*2.2; return [Math.cos(th)*r, Math.sin(th)*r, z]; }
    var ring=Math.floor(rnd(0,5)), z2=-4-ring*9, r2=7+Math.sin(z2*0.3)*0.8;
    return [Math.cos(th)*r2+rnd(-.15,.15), Math.sin(th)*r2+rnd(-.15,.15), z2+rnd(-.3,.3)];
  }
  function makeNetwork(){
    var nodes=[], edges=[], k, j;
    for (k=0;k<46;k++){
      var th=Math.random()*Math.PI*2, ph=Math.acos(rnd(-1,1)), r=rnd(5,11);
      nodes.push([Math.sin(ph)*Math.cos(th)*r*1.5, Math.cos(ph)*r*0.85, Math.sin(ph)*Math.sin(th)*r*0.8-2]);
    }
    for (k=0;k<nodes.length;k++){
      var best=-1,bd=1e9,b2=-1,b2d=1e9;
      for (j=0;j<nodes.length;j++){
        if (j===k) continue;
        var dx=nodes[k][0]-nodes[j][0], dy=nodes[k][1]-nodes[j][1], dz=nodes[k][2]-nodes[j][2];
        var d=dx*dx+dy*dy+dz*dz;
        if (d<bd){ b2=best;b2d=bd;best=j;bd=d; } else if (d<b2d){ b2=j;b2d=d; }
      }
      edges.push([k,best]); if (b2>=0) edges.push([k,b2]);
    }
    return function(i,n){
      var t=i/n;
      if (t<0.3){ var nd=nodes[Math.floor(Math.random()*nodes.length)], jt=g3()*0.5;
        return [nd[0]+rnd(-jt,jt), nd[1]+rnd(-jt,jt), nd[2]+rnd(-jt,jt)]; }
      if (t<0.85){ var e=edges[Math.floor(Math.random()*edges.length)], a=nodes[e[0]], b=nodes[e[1]], u=Math.random();
        return [a[0]+(b[0]-a[0])*u+rnd(-.07,.07), a[1]+(b[1]-a[1])*u+rnd(-.07,.07), a[2]+(b[2]-a[2])*u+rnd(-.07,.07)]; }
      return [rnd(-16,16), rnd(-9,9), rnd(-10,4)];
    };
  }
  function core(i,n){
    var t=i/n;
    if (t<0.8){ var th=Math.random()*Math.PI*2, ph=Math.acos(rnd(-1,1)), r=3.2*Math.cbrt(Math.random());
      return [Math.sin(ph)*Math.cos(th)*r, Math.cos(ph)*r, Math.sin(ph)*Math.sin(th)*r]; }
    var th2=Math.random()*Math.PI*2, r2=rnd(5.5,7.5), tilt=0.45;
    return [Math.cos(th2)*r2, Math.sin(th2)*r2*0.35*Math.cos(tilt)+rnd(-.2,.2), Math.sin(th2)*r2*Math.sin(tilt)+rnd(-.2,.2)];
  }

  function initMorphCanvas(canvas){
    var anchorEls = Array.prototype.slice.call(document.querySelectorAll('[data-morph-shape]'));
    if (!canvas || !anchorEls.length) return false;

    var renderer;
    try { renderer = new THREE.WebGLRenderer({canvas:canvas, antialias:false, alpha:true}); }
    catch(e){ canvas.style.display='none'; return false; }

    renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(55, innerWidth/innerHeight, 0.1, 200);
    camera.position.set(0, 0, 20);

    var COUNT = mobile ? 12000 : 26000;
    var geo = new THREE.BufferGeometry();
    var makers = [forest, grid, tunnel, makeNetwork(), core];
    makers.forEach(function(fn, s){
      var arr = new Float32Array(COUNT*3);
      for (var i=0;i<COUNT;i++){
        var p = fn(i, COUNT);
        arr[i*3]=p[0]; arr[i*3+1]=p[1]; arr[i*3+2]=p[2];
      }
      geo.setAttribute('p'+s, new THREE.BufferAttribute(arr,3));
    });
    geo.setAttribute('position', geo.getAttribute('p0').clone());
    var rands = new Float32Array(COUNT);
    for (var i=0;i<COUNT;i++) rands[i]=Math.random();
    geo.setAttribute('aRand', new THREE.BufferAttribute(rands,1));

    var mat = new THREE.ShaderMaterial({
      transparent:true, depthWrite:false, blending:THREE.AdditiveBlending,
      uniforms:{ uProg:{value:0}, uTime:{value:0}, uPx:{value:renderer.getPixelRatio()}, uCalm:{value:1} },
      vertexShader: [
        'attribute vec3 p0; attribute vec3 p1; attribute vec3 p2; attribute vec3 p3; attribute vec3 p4;',
        'attribute float aRand;',
        'uniform float uProg; uniform float uTime; uniform float uPx;',
        'varying float vRand; varying float vProg; varying float vBurst;',
        'void main(){',
        '  vRand = aRand; vProg = uProg;',
        '  float s = clamp(uProg, 0.0, 4.0);',
        '  vec3 a; vec3 b; float f;',
        '  if (s < 1.0){ a=p0; b=p1; f=s; }',
        '  else if (s < 2.0){ a=p1; b=p2; f=s-1.0; }',
        '  else if (s < 3.0){ a=p2; b=p3; f=s-2.0; }',
        '  else { a=p3; b=p4; f=s-3.0; }',
        '  float fp = smoothstep(0.10, 0.90, clamp(f, 0.0, 1.0));',
        '  float f2 = clamp((fp - aRand*0.45)/0.55, 0.0, 1.0);',
        '  f2 = f2*f2*f2*(f2*(f2*6.0-15.0)+10.0);',
        '  vec3 pos = mix(a, b, f2);',
        '  const float BURST_AMP = 0.35;',
        '  float burst = sin(fp*3.14159);',
        '  vBurst = burst;',
        '  vec3 dir = normalize(vec3(sin(aRand*78.233), cos(aRand*43.71), sin(aRand*12.9898+3.0)) + 0.0001);',
        '  pos += dir * burst * BURST_AMP * (1.0 + aRand*2.0);',
        '  float w = 0.18 + 0.3*step(0.9, aRand);',
        '  pos.x += sin(uTime*0.5 + aRand*40.0) * w;',
        '  pos.y += cos(uTime*0.4 + aRand*30.0) * w;',
        '  pos.z += sin(uTime*0.45 + aRand*20.0) * w * 0.6;',
        '  vec4 mv = modelViewMatrix * vec4(pos, 1.0);',
        '  float size = 1.4 + 2.6*step(0.93, aRand) + burst*0.4;',
        '  gl_PointSize = size * uPx * (110.0 / max(2.0, -mv.z));',
        '  gl_Position = projectionMatrix * mv;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'precision mediump float;',
        'uniform float uCalm;',
        'varying float vRand; varying float vProg; varying float vBurst;',
        'void main(){',
        '  vec2 uv = gl_PointCoord - 0.5;',
        '  float d = length(uv);',
        '  if (d > 0.5) discard;',
        '  float alpha = smoothstep(0.5, 0.05, d);',
        '  vec3 green = vec3(0.49, 1.0, 0.69);',
        '  vec3 deep  = vec3(0.10, 0.45, 0.28);',
        '  vec3 cyan  = vec3(0.43, 0.91, 1.0);',
        '  vec3 amber = vec3(1.0, 0.82, 0.49);',
        '  float techMix = smoothstep(0.0, 2.2, vProg) * (1.0 - smoothstep(2.9, 4.0, vProg));',
        '  vec3 col = mix(mix(deep, green, vRand), mix(cyan, vec3(0.7,0.95,1.0), vRand), techMix);',
        '  if (vRand > 0.93 && vProg < 1.2) col = mix(amber, col, smoothstep(0.6,1.2,vProg));',
        '  col += vBurst * 0.08;',
        '  float bright = 0.3 + 0.6*vRand;',
        '  float endDim = 1.0 - 0.5*smoothstep(3.1, 3.9, vProg);',
        '  float calmDim = 0.35 + 0.65*uCalm;',
        '  gl_FragColor = vec4(col, alpha * bright * 0.5 * endDim * calmDim);',
        '}'
      ].join('\n')
    });
    var points = new THREE.Points(geo, mat);
    scene.add(points);

    var anchors = [];
    function computeAnchors(){
      anchors = anchorEls.map(function(el){
        return {
          y: el.offsetTop - innerHeight*0.35,
          v: parseFloat(el.dataset.morphShape)
        };
      }).filter(function(a){ return !isNaN(a.v); }).sort(function(a,b){ return a.y-b.y; });
    }
    function progFromScroll(sy){
      if (!anchors.length) return 0;
      if (sy <= anchors[0].y) return anchors[0].v;
      for (var i=0;i<anchors.length-1;i++){
        var a=anchors[i], b=anchors[i+1];
        if (sy < b.y) return a.v + (b.v-a.v)*((sy-a.y)/(b.y-a.y));
      }
      return anchors[anchors.length-1].v;
    }

    var target = 0, prog = 0, mx=0, my=0, smx=0, smy=0;
    computeAnchors();
    target = prog = progFromScroll(scrollY);

    addEventListener('scroll', function(){ target = progFromScroll(scrollY); }, {passive:true});
    addEventListener('mousemove', function(e){
      mx = (e.clientX/innerWidth-.5)*2; my = (e.clientY/innerHeight-.5)*2;
    });
    addEventListener('resize', function(){
      camera.aspect = innerWidth/innerHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(innerWidth, innerHeight);
      computeAnchors();
      target = progFromScroll(scrollY);
    });
    renderer.setSize(innerWidth, innerHeight);

    var running = true;
    document.addEventListener('visibilitychange', function(){ running = !document.hidden; });

    function s01(a,b,x){ return Math.min(1, Math.max(0, (x-a)/(b-a))); }

    var clock = new THREE.Clock();
    (function loop(){
      requestAnimationFrame(loop);
      if (!running) return;
      var t = reduced ? 0 : clock.getElapsedTime();
      prog += (target-prog) * (reduced ? 1 : 0.035);
      const speed = Math.abs(target - prog);
      const calmTarget = 1 / (1 + speed * 6.0);
      mat.uniforms.uCalm.value += (calmTarget - mat.uniforms.uCalm.value) * 0.1;
      smx += (mx-smx)*0.04; smy += (my-smy)*0.04;
      mat.uniforms.uProg.value = prog;
      mat.uniforms.uTime.value = t;

      var inTunnel = Math.max(0, Math.min(1, prog-1.0)) * Math.max(0, Math.min(1, 3.0-prog));
      camera.position.x = smx*1.6;
      camera.position.y = -smy*1.2 + Math.sin(t*0.2)*0.15;
      camera.position.z = 20 - inTunnel*2.0;
      const lookY = 1.0 * (1 - s01(3.3, 3.8, prog));
      const lookZ = -10 * s01(1.0, 1.5, prog) * (1 - s01(2.4, 2.9, prog));
      camera.lookAt(0, lookY, lookZ);
      points.rotation.y = Math.sin(t*0.05)*0.05 + smx*0.05 + (prog>3.2 ? (t*0.06)*Math.min(1,prog-3.2) : 0);

      renderer.render(scene, camera);
    })();

    return true;
  }

  function initStaticCanvas(canvas){
    var CONF = {
      forest:  { fn:forest, tech:0.0, amber:1, cam:[0,0,20], look:[0,1,0],  rot:0.05 },
      grid:    { fn:grid,   tech:1.0, amber:0, cam:[0,1,20], look:[0,0,0],  rot:0.04 },
      tunnel:  { fn:tunnel, tech:0.7, amber:0, cam:[0,0,12], look:[0,0,-14],rot:0.0  },
      network: { fn:null,   tech:1.0, amber:0, cam:[0,0,20], look:[0,0,0],  rot:0.07 },
      core:    { fn:core,   tech:0.25,amber:0, cam:[0,0,15], look:[0,0,0],  rot:0.12 }
    };
    var name = canvas.dataset.shape;
    var conf = CONF[name]; if (!conf) return;
    var fn = conf.fn || makeNetwork();
    var renderer;
    try { renderer = new THREE.WebGLRenderer({canvas:canvas, antialias:false, alpha:true}); }
    catch(e){ canvas.style.display='none'; return; }
    renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
    var scene = new THREE.Scene();
    var holder = canvas.parentElement;
    var camera = new THREE.PerspectiveCamera(55, holder.clientWidth/holder.clientHeight, 0.1, 200);
    camera.position.set(conf.cam[0], conf.cam[1], conf.cam[2]);
    camera.lookAt(conf.look[0], conf.look[1], conf.look[2]);

    var COUNT = mobile ? 7000 : 14000;
    var geo = new THREE.BufferGeometry();
    var arr = new Float32Array(COUNT*3), rands = new Float32Array(COUNT), i, p;
    for (i=0;i<COUNT;i++){ p=fn(i,COUNT); arr[i*3]=p[0]; arr[i*3+1]=p[1]; arr[i*3+2]=p[2]; rands[i]=Math.random(); }
    geo.setAttribute('position', new THREE.BufferAttribute(arr,3));
    geo.setAttribute('aRand', new THREE.BufferAttribute(rands,1));

    var mat = new THREE.ShaderMaterial({
      transparent:true, depthWrite:false, blending:THREE.AdditiveBlending,
      uniforms:{ uTime:{value:0}, uPx:{value:renderer.getPixelRatio()},
                 uTech:{value:conf.tech}, uAmber:{value:conf.amber} },
      vertexShader: [
        'attribute float aRand;',
        'uniform float uTime; uniform float uPx;',
        'varying float vRand;',
        'void main(){',
        '  vRand = aRand;',
        '  vec3 pos = position;',
        '  float w = 0.18 + 0.3*step(0.9, aRand);',
        '  pos.x += sin(uTime*0.5 + aRand*40.0) * w;',
        '  pos.y += cos(uTime*0.4 + aRand*30.0) * w;',
        '  pos.z += sin(uTime*0.45 + aRand*20.0) * w * 0.6;',
        '  vec4 mv = modelViewMatrix * vec4(pos, 1.0);',
        '  gl_PointSize = (1.4 + 2.6*step(0.93, aRand)) * uPx * (110.0 / max(2.0, -mv.z));',
        '  gl_Position = projectionMatrix * mv;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'precision mediump float;',
        'varying float vRand;',
        'uniform float uTech; uniform float uAmber;',
        'void main(){',
        '  vec2 uv = gl_PointCoord - 0.5;',
        '  float d = length(uv);',
        '  if (d > 0.5) discard;',
        '  float alpha = smoothstep(0.5, 0.05, d);',
        '  vec3 green = vec3(0.49, 1.0, 0.69);',
        '  vec3 deep  = vec3(0.10, 0.45, 0.28);',
        '  vec3 cyan  = vec3(0.43, 0.91, 1.0);',
        '  vec3 amber = vec3(1.0, 0.82, 0.49);',
        '  vec3 col = mix(mix(deep, green, vRand), mix(cyan, vec3(0.7,0.95,1.0), vRand), uTech);',
        '  if (vRand > 0.93 && uAmber > 0.5) col = amber;',
        '  float bright = 0.3 + 0.6*vRand;',
        '  gl_FragColor = vec4(col, alpha * bright * 0.5);',
        '}'
      ].join('\n')
    });
    var points = new THREE.Points(geo, mat);
    scene.add(points);

    var mx=0,my=0,smx=0,smy=0;
    addEventListener('mousemove', function(e){
      mx=(e.clientX/innerWidth-.5)*2; my=(e.clientY/innerHeight-.5)*2;
    });
    function resize(){
      var w=holder.clientWidth, h=holder.clientHeight;
      camera.aspect=w/h; camera.updateProjectionMatrix(); renderer.setSize(w,h);
    }
    addEventListener('resize', resize); resize();

    var running=true;
    document.addEventListener('visibilitychange', function(){ running=!document.hidden; });
    var clock = new THREE.Clock();
    (function loop(){
      requestAnimationFrame(loop);
      if (!running) return;
      var t = reduced ? 0 : clock.getElapsedTime();
      mat.uniforms.uTime.value = t;
      smx += (mx-smx)*0.04; smy += (my-smy)*0.04;
      points.rotation.y = t*conf.rot + smx*0.06;
      camera.position.x = conf.cam[0] + smx*1.2;
      camera.position.y = conf.cam[1] - smy*0.8;
      camera.lookAt(conf.look[0], conf.look[1], conf.look[2]);
      renderer.render(scene, camera);
    })();
  }

  var morphCanvas = document.getElementById('gl');
  if (document.body.classList.contains('page-1') && initMorphCanvas(morphCanvas)) return;

  document.querySelectorAll('canvas[data-shape]').forEach(initStaticCanvas);
})();
