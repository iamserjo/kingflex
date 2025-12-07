import * as THREE from 'three';

export function initBucket3D(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    // Scene setup
    const scene = new THREE.Scene();
    scene.background = null;

    // Camera
    const camera = new THREE.PerspectiveCamera(40, container.clientWidth / container.clientHeight, 0.1, 1000);
    camera.position.set(0, 1.5, 7);
    camera.lookAt(0, 0, 0);

    // Renderer
    const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2)); // Cap pixel ratio for performance
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.2;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    container.appendChild(renderer.domElement);

    // --- Materials ---

    // Cloud: Frosted Glass / Aerogel look
    const cloudMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xffffff,
        metalness: 0.1,
        roughness: 0.2,
        transmission: 0.4, // Glass-like
        thickness: 1.5,
        clearcoat: 1.0,
        clearcoatRoughness: 0.1,
        ior: 1.5,
        attenuationColor: 0xccf0ff, // Blueish tint inside
        attenuationDistance: 1.0
    });

    // Bucket: Premium Matte Plastic
    const bucketMaterial = new THREE.MeshStandardMaterial({
        color: 0xff6600, // Deep Orange
        metalness: 0.1,
        roughness: 0.3,
    });

    // Metal Accents
    const metalMaterial = new THREE.MeshStandardMaterial({
        color: 0xffffff,
        metalness: 0.9,
        roughness: 0.2
    });

    // --- Geometry Construction ---

    const mainGroup = new THREE.Group();
    scene.add(mainGroup);

    // 1. The Cloud (Stylized, smaller, smoother)
    const cloudGroup = new THREE.Group();

    // Use fewer, larger spheres for a cleaner "icon" look
    const cloudGeom = new THREE.SphereGeometry(1, 48, 48);

    const blobs = [
        { x: 0, y: 0, z: 0, s: 1.0 },
        { x: 0.8, y: -0.1, z: 0.2, s: 0.7 },
        { x: -0.8, y: -0.1, z: 0.2, s: 0.75 },
        { x: 0.4, y: 0.3, z: -0.4, s: 0.6 },
        { x: -0.4, y: 0.2, z: -0.4, s: 0.65 },
    ];

    blobs.forEach(b => {
        const m = new THREE.Mesh(cloudGeom, cloudMaterial);
        m.position.set(b.x, b.y, b.z);
        m.scale.set(b.s, b.s * 0.6, b.s); // Flattened
        m.castShadow = true;
        m.receiveShadow = true;
        cloudGroup.add(m);
    });

    cloudGroup.scale.set(1.2, 1.2, 1.2); // Overall size
    cloudGroup.position.y = -1.0;
    mainGroup.add(cloudGroup);


    // 2. The Bucket (Floating above)
    const bucketGroup = new THREE.Group();

    // Body
    const bucketBody = new THREE.Mesh(
        new THREE.CylinderGeometry(0.55, 0.45, 0.9, 64, 1, true),
        bucketMaterial
    );
    bucketBody.castShadow = true;
    bucketBody.receiveShadow = true;
    bucketGroup.add(bucketBody);

    // Inner Floor
    const bucketFloor = new THREE.Mesh(
        new THREE.CircleGeometry(0.45, 64),
        bucketMaterial
    );
    bucketFloor.rotation.x = -Math.PI / 2;
    bucketFloor.position.y = -0.45;
    bucketGroup.add(bucketFloor);

    // Rim
    const rim = new THREE.Mesh(
        new THREE.TorusGeometry(0.55, 0.04, 32, 100),
        metalMaterial
    );
    rim.rotation.x = Math.PI / 2;
    rim.position.y = 0.45;
    bucketGroup.add(rim);

    // Handle (Upright)
    const handle = new THREE.Mesh(
        new THREE.TorusGeometry(0.55, 0.03, 16, 100, Math.PI),
        metalMaterial
    );
    handle.position.y = 0.45;
    handle.rotation.z = 0;
    bucketGroup.add(handle);

    // Floating "Data" Particles inside
    const particles = new THREE.Group();
    const pGeom = new THREE.SphereGeometry(0.08, 16, 16);
    const pMat = new THREE.MeshBasicMaterial({ color: 0xffffff });

    for (let i = 0; i < 5; i++) {
        const p = new THREE.Mesh(pGeom, pMat);
        p.position.set(
            (Math.random() - 0.5) * 0.5,
            (Math.random() - 0.5) * 0.5,
            (Math.random() - 0.5) * 0.5
        );
        particles.add(p);
    }
    bucketGroup.add(particles);

    bucketGroup.position.y = 0.5;
    mainGroup.add(bucketGroup);


    // --- Lighting ---
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.7);
    scene.add(ambientLight);

    const spotLight = new THREE.SpotLight(0xffaa00, 10);
    spotLight.position.set(5, 8, 5);
    spotLight.angle = Math.PI / 6;
    spotLight.penumbra = 1;
    spotLight.castShadow = true;
    spotLight.shadow.bias = -0.0001;
    scene.add(spotLight);

    const blueLight = new THREE.PointLight(0x0088ff, 2);
    blueLight.position.set(-5, 0, 2);
    scene.add(blueLight);


    // --- Animation ---
    let time = 0;

    function animate() {
        requestAnimationFrame(animate);
        time += 0.01;

        // Cloud: Gentle float
        cloudGroup.position.y = -1.0 + Math.sin(time * 0.8) * 0.1;
        cloudGroup.rotation.y = Math.sin(time * 0.2) * 0.05;

        // Bucket: Float above cloud, slightly out of sync
        bucketGroup.position.y = 0.5 + Math.sin(time * 1.1) * 0.15;
        bucketGroup.rotation.y = time * 0.2; // Slow spin for the bucket
        bucketGroup.rotation.z = Math.sin(time * 0.5) * 0.05;

        // Particles: Orbit inside
        particles.rotation.y = -time * 0.5;
        particles.rotation.x = Math.sin(time) * 0.2;

        renderer.render(scene, camera);
    }

    animate();

    window.addEventListener('resize', () => {
        if (!container) return;
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    });
}
