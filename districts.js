const districtCounts = { //hardcoded districts
    AL:7, AK:1, AZ:9, AR:4, CA:52, CO:8, CT:5, DE:1, DC:1, FL:28, GA:14,
    HI:2, ID:2, IL:17, IN:9, IA:4, KS:4, KY:6, LA:6, ME:2, MD:8,
    MA:9, MI:13, MN:8, MS:4, MO:8, MT:2, NE:3, NV:4, NH:2, NJ:12,
    NM:3, NY:26, NC:14, ND:1, OH:15, OK:5, OR:6, PA:17, RI:2, SC:7,
    SD:1, TN:9, TX:38, UT:4, VT:1, VA:11, WA:10, WV:2, WI:8, WY:1
    };

const stateSelect = document.getElementById('createUserState');
const districtSelect = document.getElementById('createUserDistrict');

stateSelect.addEventListener('change', () => {
    const n = districtCounts[stateSelect.value] || 0;
    districtSelect.innerHTML = '<option value="" selected>Select a district</option>';

    if (n === 0) {
        districtSelect.disabled = true;
        return;
    }

    if (n === 1) {
        const label = stateSelect.value === 'DC' ? 'Delegate (at-large)' : 'At-large';
        districtSelect.add(new Option(label, 1));
    } else {
        for (let i = 1; i <= n; i++) {
        districtSelect.add(new Option('District ' + i, i));
        }
    }
    districtSelect.disabled = false;
});